<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Library\Constants\TransactionEvents;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class NMIWebhookService
{
    private NMIConfigService $configService;
    private NmiTransactionService $transactionService;
    private EntityRepository $orderTransactionRepository;
    private EntityRepository $orderRepository;
    private OrderTransactionStateHandler $transactionStateHandler;
    private LoggerInterface $logger;

    public function __construct(
        NMIConfigService $configService,
        NmiTransactionService $transactionService,
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderRepository,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger
    ) {
        $this->configService = $configService;
        $this->transactionService = $transactionService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->logger = $logger;
    }

    public function verifySignature(string $webhookBody, ?string $signatureHeader): bool
    {
        if (!$signatureHeader) {
            return false;
        }

        $signingKey = $this->configService->getConfig('signingKey');
        if (!$signingKey) {
            $this->logger->warning('NMI Webhook: Signing key not configured');
            return false;
        }

        if (!preg_match('/t=(.*),s=(.*)/', $signatureHeader, $matches)) {
            return false;
        }

        $nonce = $matches[1];
        $signature = $matches[2];

        $expectedSignature = hash_hmac('sha256', $nonce . '.' . $webhookBody, $signingKey);

        return hash_equals($expectedSignature, $signature);
    }

    public function processWebhook(array $webhookData): void
    {
        $eventType = $webhookData['event_type'] ?? null;
        $eventBody = $webhookData['event_body'] ?? [];
        $transactionId = $eventBody['transaction_id'] ?? null;

        if (!$eventType || !$transactionId) {
            $this->logger->warning('NMI Webhook: Missing required fields', ['data' => $webhookData]);
            return;
        }

        $this->logger->info('NMI Webhook received', [
            'event_type' => $eventType,
            'transaction_id' => $transactionId,
        ]);

        if (TransactionEvents::isVoidEvent($eventType) && TransactionEvents::isSuccessfulEvent($eventType)) {
            $this->handleVoidEvent($webhookData);
        } elseif (TransactionEvents::isRefundEvent($eventType) && TransactionEvents::isSuccessfulEvent($eventType)) {
            $this->handleRefundEvent($webhookData);
        }
    }

    private function handleVoidEvent(array $webhookData): void
    {
        $eventBody = $webhookData['event_body'] ?? [];
        $transactionId = $eventBody['transaction_id'] ?? null;

        if (!$transactionId) {
            $this->logger->warning('NMI Webhook: Missing transaction ID in void event', ['data' => $webhookData]);
            return;
        }

        $context = Context::createDefaultContext();
        $transaction = $this->transactionService->getTransactionByTransactionId($transactionId, $context);

        if (!$transaction) {
            $this->logger->warning('NMI Webhook: Transaction not found for void', ['transaction_id' => $transactionId]);
            return;
        }

        $orderId = $transaction->getOrderId();
        if (!$orderId) {
            $this->logger->warning('NMI Webhook: Order ID not found in transaction for void', ['transaction_id' => $transactionId]);
            return;
        }

        $orderTransactionId = $this->getOrderTransactionIdByOrderId($orderId, $context);
        if (!$orderTransactionId) {
            $this->logger->warning('NMI Webhook: Order transaction not found for void', ['order_id' => $orderId]);
            return;
        }

        $this->transactionStateHandler->cancel($orderTransactionId, $context);
        $this->transactionService->updateTransactionStatus($orderId, 'voided', $context);
        $this->logger->info('NMI Webhook: Order voided', ['order_id' => $orderId, 'transaction_id' => $transactionId]);
    }

    private function handleRefundEvent(array $webhookData): void
    {
        $eventBody = $webhookData['event_body'] ?? [];
        $transactionId = $eventBody['transaction_id'] ?? null;
        $action = $eventBody['action'] ?? [];
        $refundAmount = abs((float)($action['amount'] ?? 0));

        if (!$transactionId) {
            $this->logger->warning('NMI Webhook: Missing transaction ID in refund event', ['data' => $webhookData]);
            return;
        }

        if ($refundAmount <= 0) {
            $this->logger->warning('NMI Webhook: Invalid refund amount', ['amount' => $refundAmount, 'transaction_id' => $transactionId]);
            return;
        }

        $context = Context::createDefaultContext();
        $transaction = $this->transactionService->getTransactionByTransactionId($transactionId, $context);

        if (!$transaction) {
            $this->logger->warning('NMI Webhook: Transaction not found for refund', ['transaction_id' => $transactionId]);
            return;
        }

        $orderId = $transaction->getOrderId();
        if (!$orderId) {
            $this->logger->warning('NMI Webhook: Order ID not found in transaction for refund', ['transaction_id' => $transactionId]);
            return;
        }

        $order = $this->getOrderById($orderId, $context);
        if (!$order) {
            $this->logger->warning('NMI Webhook: Order not found', ['order_id' => $orderId]);
            return;
        }

        $orderTotalAmount = abs($order->getAmountTotal());
        $isPartialRefund = $refundAmount < $orderTotalAmount;

        $orderTransactionId = $this->getOrderTransactionIdByOrderId($orderId, $context);
        if (!$orderTransactionId) {
            $this->logger->warning('NMI Webhook: Order transaction not found for refund', ['order_id' => $orderId]);
            return;
        }

        if ($isPartialRefund) {
            $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
            $this->transactionService->updateTransactionStatus($orderId, 'partially_refunded', $context);
            $this->logger->info('NMI Webhook: Order partially refunded', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'refund_amount' => $refundAmount,
                'order_total' => $orderTotalAmount
            ]);
        } else {
            $this->transactionStateHandler->refund($orderTransactionId, $context);
            $this->transactionService->updateTransactionStatus($orderId, 'refunded', $context);
            $this->logger->info('NMI Webhook: Order fully refunded', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
                'refund_amount' => $refundAmount,
                'order_total' => $orderTotalAmount
            ]);
        }
    }

    private function getOrderById(string $orderId, Context $context)
    {
        $criteria = new Criteria([$orderId]);
        return $this->orderRepository->search($criteria, $context)->first();
    }

    private function getOrderTransactionIdByOrderId(string $orderId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        
        return $orderTransaction ? $orderTransaction->getId() : null;
    }
}

