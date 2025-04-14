<?php declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderVoidNmiPayment implements EventSubscriberInterface
{
    private NmiTransactionService $nmiTransactionService;

    private NMIPaymentApiClient $nmiPaymentApiClient;

    private NMIConfigService $nmiConfigService;

    private EntityRepository $orderRepository;

    private EntityRepository $orderTransactionRepository;

    private EntityRepository $orderDeliveryRepository;

    private LoggerInterface $logger;

    public function __construct(
        NmiTransactionService $nmiTransactionService,
        NMIPaymentApiClient $nmiPaymentApiClient,
        NMIConfigService $nmiConfigService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderDeliveryRepository,
        LoggerInterface $logger
    ) {
        $this->nmiTransactionService = $nmiTransactionService;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->nmiConfigService = $nmiConfigService;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
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

        if ($entityName !== 'order_transaction') {
            return;
        }

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order');
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $event->getContext())->first();
        $testOrderId = $orderTransaction->getOrderId();

        $criteria = new Criteria([$testOrderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $order = $this->orderRepository->search($criteria, $event->getContext())->first();
        $getTransaction = $order->getTransactions()->getElements();
        $paymentMethodName = reset($getTransaction)->getPaymentMethod()->getName();

        if ($paymentMethodName !== 'NMI Credit Card') {
            return;
        }

        if ($nextState === 'cancelled' || $nextState === 'paid') {
            try {
                if ($nextState === 'cancelled') {
                    $order = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext());
                    $orderId = $order->getOrderId();
                    $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());
                    $orderAmount = $order->getAmount()->getTotalPrice();

                    if ($transaction) {
                        $postData = [
                            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
                            'type' => 'void',
                            'transactionid' => $transaction->getTransactionId(),
                            'payment' => 'creditcard',
                            'void_reason' => 'fraud',
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
                    $order = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext());
                    $orderId = $order->getOrderId();
                    $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());

                    $orderAmount = $order->getAmount()->getTotalPrice();
                    if ($transaction) {
                        $criteria = new Criteria([$orderId]);
                        $criteria->addAssociation('lineItems');
                        $order = $this->orderRepository->search($criteria, $event->getContext())->first();

                        if (!$order) {
                            $this->logger->error('Order entity not found for ID: ' . $orderId);

                            return;
                        }
                        $postData = [
                            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
                            'type' => 'capture',
                            'transactionid' => $transaction->getTransactionId(),
                            'amount' => $orderAmount,
                        ];
                        $response = $this->nmiPaymentApiClient->createTransaction($postData);
                        $this->logger->info(json_encode($response));
                        $this->nmiTransactionService->updateTransactionStatus($orderId, $nextState, $event->getContext());
                    }
                }
            } catch (\Exception $exception) {
                $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
                    'exception' => $exception->getMessage(),
                ]);
            }
        } else {
            $this->logger->info('Skipping state transition for state: ' . $nextState);
        }
    }
}
