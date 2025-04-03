<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;

class OrderVoidNmiPayment implements EventSubscriberInterface
{
    private NmiTransactionService $nmiTransactionService;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private NMIConfigService $nmiConfigService;
    private EntityRepository $orderRepository;
    private EntityRepository $orderTransactionRepository;
    private EntityRepository $partialDeliveryRepository;
    private EntityRepository $orderDeliveryRepository;

    private LoggerInterface $logger;

    public function __construct(
        nmiTransactionService $nmiTransactionService,
        NMIPaymentApiClient $nmiPaymentApiClient,
        NMIConfigService $nmiConfigService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $partialDeliveryRepository,
        EntityRepository $orderDeliveryRepository,
        LoggerInterface $logger
    ) {
        $this->nmiTransactionService      = $nmiTransactionService;
        $this->nmiPaymentApiClient        = $nmiPaymentApiClient;
        $this->nmiConfigService           = $nmiConfigService;
        $this->orderRepository            = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->partialDeliveryRepository  = $partialDeliveryRepository;
        $this->orderDeliveryRepository    = $orderDeliveryRepository;
        $this->logger                     = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [

          StateMachineTransitionEvent::class => 'onStateMachineTransition',
        ];
    }

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        $nextState = $event->getToPlace()->getTechnicalName();

        $entityName = $event->getEntityName();

        $transactionId = $event->getEntityId();


        if ($entityName !== "order_transaction") {
            return;
        }

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order');
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $event->getContext())->first();
        $testOrderId      = $orderTransaction->getOrderId();


        $criteria = new Criteria([$testOrderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $order             = $this->orderRepository->search($criteria, $event->getContext())->first();
        $getTransaction    = $order->getTransactions()->getElements();
        $paymentMethodName = reset($getTransaction)->getPaymentMethod()->getName();

        if ($paymentMethodName !== 'NMI Credit Card') {
            return;
        }


        if ($nextState === 'cancelled' || $nextState === 'paid') {
            try {
                if ($nextState == 'cancelled') {
                    $orderId     = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext())->getOrderId();
                    $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());
                    $orderAmount = ($this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext()))->getAmount()->getTotalPrice();

                    if ($transaction) {
                        $postData = [
                          'security_key'  => $this->nmiConfigService->getConfig('privateKeyApi'),
                          'type'          => 'void',
                          'transactionid' => $transaction->getTransactionId(),
                          'payment'       => 'creditcard',
                          'void_reason'   => 'fraud'
                        ];
                        $response = $this->nmiPaymentApiClient->createTransaction($postData);
                        $this->logger->info(json_encode($response));
                        try {
                            $this->nmiTransactionService->updateTransactionStatus($orderId, $nextState, $event->getContext());
                        } catch (\Exception $exception) {
                            $this->logger->error($exception->getMessage());
                        }
                    }
                }

                if ($nextState === 'paid') {
                    $orderId       = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext())->getOrderId();
                    $order         = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext());
                    $transaction   = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());
                    $canceledItems = $this->getShipmentsByOrderId($orderId, $event->getContext());

                    $orderAmount = $order->getAmount()->getTotalPrice();
                    if ($transaction) {
                        $criteria = new Criteria([$orderId]);
                        $criteria->addAssociation('lineItems');
                        $order = $this->orderRepository->search($criteria, $event->getContext())->first();

                        if (!$order) {
                            $this->logger->error('Order entity not found for ID: ' . $orderId);
                            return;
                        }

                        $orderAmount = 0;
                        $lineItems   = $order->getLineItems();

                        foreach ($lineItems as $lineItem) {
                            $orderAmount += $lineItem->getPrice()->getTotalPrice();

                            if ($lineItem->getPayload() && isset($lineItem->getPayload()['excise_calculated_taxes']['cart']['taxes'][0]['unit_tax_amount'])) {
                                $unitTaxAmount = $lineItem->getPayload()['excise_calculated_taxes']['cart']['taxes'][0]['unit_tax_amount'];
                                $quantity      = $lineItem->getQuantity();
                                $orderAmount += ($unitTaxAmount * $quantity);
                            }
                        }
                    }

                    if (!empty($canceledItems)) {
                        foreach ($canceledItems as $canceledItem) {
                            $lineItemId       = $canceledItem['lineItemId'];
                            $quantityCanceled = $canceledItem['quantityCanceled'];

                            foreach ($lineItems as $lineItem) {
                                if ($lineItem->getId() === $lineItemId) {
                                    $unitPrice = $lineItem->getPrice()->getUnitPrice();
                                    $deduction = $unitPrice * $quantityCanceled;
                                    $orderAmount -= $deduction;

                                    if ($lineItem->getPayload() && isset($lineItem->getPayload()['excise_calculated_taxes']['cart']['taxes'][0]['unit_tax_amount'])) {
                                        $unitTaxAmount = $lineItem->getPayload()['excise_calculated_taxes']['cart']['taxes'][0]['unit_tax_amount'];
                                        $orderAmount -= ($unitTaxAmount * $quantityCanceled);
                                    }
                                }
                            }
                        }
                    }

                    $orderAmount = max(0, round($orderAmount, 2));

                    $postData = [
                      'security_key'  => $this->nmiConfigService->getConfig('privateKeyApi'),
                      'type'          => 'capture',
                      'transactionid' => $transaction->getTransactionId(),
                      'amount'        => $orderAmount,
                    ];
                    $response = $this->nmiPaymentApiClient->createTransaction($postData);
                    $this->logger->info(json_encode($response));
                    $this->nmiTransactionService->updateTransactionStatus($orderId, $nextState, $event->getContext());
                }
            } catch (\Exception $exception) {
                $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
                  'exception' => 'edon'
                ]);
            }
        } else {
            $this->logger->info('Skipping state transition for state: ' . $nextState);
        }
    }

    public function getShipmentsByOrderId(string $orderId, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('positions');
        $orderDeliveries = $this->orderDeliveryRepository->search($criteria, $context)->getEntities();

        $lineItems = [];
        foreach ($orderDeliveries as $orderDelivery) {
            foreach ($orderDelivery->getPositions()->getElements() as $position) {
                $lineItems[$position->getOrderLineItemId()] = [
                  'lineItemId'      => $position->getOrderLineItemId(),
                  'totalOrdered'    => $position->getQuantity(),
                  'shippedQuantity' => 0
                ];
            }
        }

        if (empty($lineItems)) {
            return null;
        }

        foreach ($lineItems as &$lineItem) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderLineItemId', $lineItem['lineItemId']));
            $partialDeliveriesShipments = $this->partialDeliveryRepository->search($criteria, $context)->getElements();

            if (empty($partialDeliveriesShipments)) {
                return null;
            }

            foreach ($partialDeliveriesShipments as $shipment) {
                $lineItem['shippedQuantity'] += $shipment->getQuantity();
            }

            $lineItem['quantityCanceled'] = max(0, $lineItem['totalOrdered'] - $lineItem['shippedQuantity']);
        }

        return array_map(fn ($lineItem) => [
          'lineItemId'       => $lineItem['lineItemId'],
          'totalOrdered'     => $lineItem['totalOrdered'],
          'quantityCanceled' => $lineItem['quantityCanceled']
        ], array_values($lineItems));
    }
}