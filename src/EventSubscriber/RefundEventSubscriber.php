<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RefundEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NmiTransactionService $nmiTransactionService,
        private readonly NMIPaymentApiClient $nmiPaymentApiClient,
        private readonly ?EntityRepository $orderReturnRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly NMIConfigService $nmiConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents()
    {
        return [
            'state_enter.order_return.state.in_progress' => 'onProgressRefund',
        ];
    }

    public function onProgressRefund(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $context = $event->getContext();
            $order = $event->getOrder();
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $order->getId()));
            $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();

            if (!$orderReturn) {
                $this->logger->warning('No order return found for orderId: ' . $order->getId());
                return;
            }

            $transaction = $this->nmiTransactionService->getTransactionByOrderId($order->getId(), $context);
            $orderTransactionId = $this->getOrderTransactionIdByOrderId($order->getId(), $context);

            if (!$orderTransactionId) {
                $this->logger->warning('No order transaction found for refund process on orderId: ' . $order->getId());
                return;
            }
            /** @var OrderEntity $order */
            /** @var OrderEntity $orderReturn */
            /** @var NmiTransactionEntity|null $transaction */
            $orderTotalAmount = $order->getAmountTotal();
            /** @var NmiTransactionEntity|null $transaction */

            $salesChannelId = $order->getSalesChannelId();

            if ($transaction && strtolower($transaction->getStatus()) === strtolower(TransactionStatuses::PAID->value)) {
                $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
                $isLive = $mode === 'live';
                $securityKey = $this->nmiConfigService->getConfig(
                    $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
                    $salesChannelId
                );

                $this->nmiPaymentApiClient->initializeForSalesChannel($salesChannelId);
                $postData = [
                    'security_key' => $securityKey,
                    'type' => 'refund',
                    'transactionid' => $transaction->getTransactionId(),
                    'payment' => 'creditcard',
                    'amount' => $orderReturn->getAmountTotal(),
                ];

                $response = $this->nmiPaymentApiClient->createTransaction($postData);
                if ($response['responsetext'] === 'SUCCESS') {
                    if ($orderReturn->getAmountTotal() === $orderTotalAmount) {
                        $this->transactionStateHandler->refund($orderTransactionId, $context);
                    } else {
                        $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->log('Refund processing failed due to: ', $e, [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function getOrderTransactionIdByOrderId(string $orderId, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        /** @var OrderTransactionEntity|null $orderTransaction */
        if ($orderTransaction) {
            return $orderTransaction->getId();
        }

        return null;
    }
}
