<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Gateways\CreditCard;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use NMIPayment\Service\NmiCaptureService;
use NMIPayment\Service\NmiRefundService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;

class OrderVoidNmiPayment implements EventSubscriberInterface
{
    private NmiTransactionService $nmiTransactionService;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private NMIConfigService $nmiConfigService;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
    private EntityRepository $orderRepository;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
    private EntityRepository $orderTransactionRepository;
    private LoggerInterface $logger;
    private NmiCaptureService $captureService;
    private NmiRefundService $refundService;

    public function __construct(
        NmiTransactionService $nmiTransactionService,
        NMIPaymentApiClient $nmiPaymentApiClient,
        NMIConfigService $nmiConfigService,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger,
        NmiCaptureService $captureService,
        NmiRefundService $refundService
    ) {
        $this->nmiTransactionService = $nmiTransactionService;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->nmiConfigService = $nmiConfigService;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
        $this->captureService = $captureService;
        $this->refundService = $refundService;
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

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $event->getContext())->getEntities()->first();
        $handlerIdf = $orderTransaction->getPaymentMethod()->getHandlerIdentifier();


        if ($handlerIdf !== CreditCard::class) {
            return;
        }

        if ($nextState === 'cancelled' || $nextState === 'paid') {
            try {
                if ($nextState == 'cancelled') {
                    $orderId = $this->nmiTransactionService->getOrderByTransactionId($event->getEntityId(), $event->getContext())->getOrderId();
                    $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $event->getContext());

                    if ($transaction && \in_array(strtolower((string) $transaction->getStatus()), ['cancelled', 'voided'], true)) {
                        $this->logger->info('NMI transaction already voided, skipping void', ['orderId' => $orderId]);
                        $transaction = null;
                    }

                    if ($transaction) {
                        $criteria = new Criteria([$orderId]);
                        $criteria->addAssociation('lineItems');
                        $criteria->addAssociation('salesChannel');
                        $order = $this->orderRepository->search($criteria, $event->getContext())->getEntities()->first();

                        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
                        $mode = $this->nmiConfigService->getConfig('mode', $order->getSalesChannelId());
                        $isLive = $mode === 'live';
                        $securityKey = $this->nmiConfigService->getConfig(
                            $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
                            $order->getSalesChannelId()
                        );

                        $postData = [
                        'security_key' => $securityKey,
                        'type' => 'void',
                        'transactionid' => $transaction->getTransactionId(),
                        'payment' => 'creditcard',
                        'void_reason' => 'fraud'
                        ];
                        $response = $this->nmiPaymentApiClient->createTransaction($postData);
                        $this->logger->info('NMI void response', [
                            'transaction_id' => $response['transactionid'] ?? null,
                            'response_code'  => $response['response'] ?? null,
                        ]);
                        if (($response['response'] ?? null) !== '1') {
                            $this->logger->error('NMI void failed; local transaction status was not changed', [
                                'orderId' => $orderId,
                                'response_code' => $response['response'] ?? null,
                                'response_text' => $response['responsetext'] ?? null,
                            ]);
                            return;
                        }
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
                    $this->captureService->captureForOrder($orderId, $event->getContext());
                }
            } catch (\Exception $exception) {
                $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
                'exception' => $exception
                ]);
            }
        } elseif ($nextState === 'refunded') {
            try {
                $order = $orderTransaction->getOrder();
                if ($order === null) {
                    return;
                }

                $outcome = $this->refundService->refund(
                    $orderTransaction->getId(),
                    $order->getId(),
                    $order->getSalesChannelId(),
                    null,
                    'creditcard',
                    $event->getContext()
                );

                if ($outcome['result'] !== NmiRefundService::RESULT_REFUNDED && $outcome['result'] !== NmiRefundService::RESULT_SKIPPED) {
                    $this->logger->error('Order transaction marked refunded but the NMI refund did not go through', [
                        'orderId' => $order->getId(),
                        'result' => $outcome['result'],
                    ]);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Error during state machine transition: ' . $exception->getMessage(), [
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
