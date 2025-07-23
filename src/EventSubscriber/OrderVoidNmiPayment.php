<?php

namespace NMIPayment\EventSubscriber;

use ArrayObject;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
  private ?EntityRepository$partialDeliveryRepository;
  private EntityRepository $orderDeliveryRepository;
  private LoggerInterface $logger;

  public function __construct(
    NmiTransactionService $nmiTransactionService,
    NMIPaymentApiClient $nmiPaymentApiClient,
    NMIConfigService $nmiConfigService,
    EntityRepository $orderRepository,
    EntityRepository $orderTransactionRepository,
    ?EntityRepository $partialDeliveryRepository,
    EntityRepository $orderDeliveryRepository,
    LoggerInterface $logger
  ) {
    $this->nmiTransactionService = $nmiTransactionService;
    $this->nmiPaymentApiClient = $nmiPaymentApiClient;
    $this->nmiConfigService = $nmiConfigService;
    $this->orderRepository = $orderRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->partialDeliveryRepository = $partialDeliveryRepository;
    $this->orderDeliveryRepository = $orderDeliveryRepository;
    $this->logger = $logger;
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

    $criteria = (new Criteria([$transactionId]))
      ->addAssociation('paymentMethod')
      ->addAssociation('order');

    $orderTransaction = $this->orderTransactionRepository->search($criteria, $event->getContext())->first();
    $handlerIdf = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();


    if ($handlerIdf !== CreditCard::class) {
      return;
    }

    if ($nextState === 'cancelled' || $nextState === 'paid') {

      try {
        if ($nextState == 'cancelled') {
          $orderId = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext())->getOrderId();
          $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());
          $orderAmount = ($this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext()))->getAmount()->getTotalPrice();

          if ($transaction) {

            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('salesChannel');
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
            $securityKey = $this->nmiConfigService->getConfig('privateKeyApi', $order->getSalesChannelId());


            $postData = [
              'security_key' => $securityKey,
              'type' => 'void',
              'transactionid' => $transaction->getTransactionId(),
              'payment' => 'creditcard',
              'void_reason' => 'fraud'
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

        $orderAmount = 0;
        $orderCaptured = 0;
        if ($nextState === 'paid') {
          $orderId = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext())->getOrderId();
          $order = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext());
          $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());
          $canceledItems = $this->getShipmentsByOrderId($orderId, $event->getContext());


          if ($transaction) {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('salesChannel');
            $order = $this->orderRepository->search($criteria, $event->getContext())->first();

            if (!$order) {
              $this->logger->error('Order entity not found for ID: ' . $orderId);
              return;
            }

            $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
            $securityKey = $this->nmiConfigService->getConfig('privateKeyApi', $order->getSalesChannelId());

            $lineItems = $order->getLineItems();
            $addShippingCosts = $order->getShippingCosts()->getTotalPrice() ?? 0;

            if (empty($canceledItems)) {
              $orderAmount = $order->getAmountTotal();
            }

            if (!empty($canceledItems)) {
              foreach ($canceledItems as $canceledItem) {
                $lineItemId = $canceledItem['lineItemId'];
                $quantityCanceled = $canceledItem['quantityCanceled'];

                foreach ($lineItems as $lineItem) {
                  if ($lineItem->getId() === $lineItemId) {
                    $unitPrice = $lineItem->getPrice()->getUnitPrice();
                    $deduction = $unitPrice * $quantityCanceled;

                    $calculatedTaxes = $lineItem->getPrice()->getCalculatedTaxes();
                    $taxDeduction = 0;
                    $totalQuantity = $lineItem->getQuantity();

                    foreach ($calculatedTaxes->getElements() as $taxElement) {
                      $totalTax = $taxElement->getTax();
                      $taxPerUnit = $totalTax / $totalQuantity;
                      $taxDeduction += $taxPerUnit * $quantityCanceled;
                    }

                    $orderCaptured += ($deduction + $taxDeduction);
                  }
                }
              }
              $orderCaptured += $addShippingCosts;
            }

            if ($orderCaptured !== 0) {
              $orderAmount = max(0, round($orderCaptured, 2));
            } else {
              $orderAmount = max(0, round($orderAmount, 2));
            }

            $postData = [
              'security_key' => $securityKey,
              'type' => 'capture',
              'transactionid' => $transaction->getTransactionId(),
              'amount' => $orderAmount,
              'orderid' => $order->getOrderNumber()
            ];

            $customFields = $order->getCustomFields() ?? [];
            $customFields['NmiPaymentAmountCapture'] = $orderAmount;

            $this->orderRepository->update([
              [
                'id' => $orderId,
                'customFields' => $customFields,
              ],
            ], $event->getContext());


            $response = $this->nmiPaymentApiClient->createTransaction($postData);
            $this->logger->info(json_encode($response));

            $this->nmiTransactionService->updateTransactionStatus($orderId, $nextState, $event->getContext());
          }
        }
      } catch (\Exception $exception) {
        $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
          'exception' => $exception
        ]);
      }
    } else if ($nextState === 'refunded') {
      try {
        $orderId = $this->nmiTransactionService
          ->getOrderByTransactionId($event->getEntityId(), $event->getContext())
          ->getOrderId();

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('salesChannel');

        $order = $this->orderRepository->search($criteria, $event->getContext())->first();

        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
        $securityKey = $this->nmiConfigService->getConfig('privateKeyApi', $order->getSalesChannelId());

        $transaction = $this->nmiTransactionService
          ->getTransactionByOrderId($order->getId(), $event->getContext());

        if ($transaction && strtolower($transaction->getStatus()) === strtolower(TransactionStatuses::PAID->value)) {
          $postData = [
            'security_key'   => $securityKey,
            'type'           => 'refund',
            'transactionid'  => $transaction->getTransactionId(),
            'payment'        => 'creditcard',
            'amount'         => $order->getAmountTotal(),
          ];

          $response = $this->nmiPaymentApiClient->createTransaction($postData);
          $this->nmiTransactionService->updateTransactionStatus($orderId, $nextState, $event->getContext());

        }
      } catch (\Throwable $exception) {
        $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
          'exception' => $exception,
        ]);
      }
    }
  }




  public function getShipmentsByOrderId(string $orderId, Context $context): ?array
  {
    if ($this->partialDeliveryRepository === null) {
      $this->logger->info('Partial delivery repository not available. Skipping shipment check.');
      return null;
    }

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $criteria->addAssociation('positions');
    $orderDeliveries = $this->orderDeliveryRepository->search($criteria, $context)->getEntities();

    $lineItems = [];
    foreach ($orderDeliveries as $orderDelivery) {
      foreach ($orderDelivery->getPositions()->getElements() as $position) {
        $lineItems[$position->getOrderLineItemId()] = [
          'lineItemId' => $position->getOrderLineItemId(),
          'totalOrdered' => $position->getQuantity(),
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

      try {
        $partialDeliveriesShipments = $this->partialDeliveryRepository->search($criteria, $context)->getElements() ?? null;
      } catch (\Exception $exception) {
        $this->logger->error('Error querying partial delivery repository: ' . $exception->getMessage());
        continue;
      }

      if (empty($partialDeliveriesShipments)) {
        continue;
      }

      foreach ($partialDeliveriesShipments as $shipment) {
        $lineItem['shippedQuantity'] += $shipment->getQuantity();
      }

      $lineItem['quantityCanceled'] = max(0, $lineItem['totalOrdered'] - $lineItem['shippedQuantity']);
    }

    return array_map(fn($lineItem) => [
      'lineItemId' => $lineItem['lineItemId'],
      'totalOrdered' => $lineItem['totalOrdered'],
      'quantityCanceled' => $lineItem['quantityCanceled']
    ], array_values($lineItems));
  }

}
