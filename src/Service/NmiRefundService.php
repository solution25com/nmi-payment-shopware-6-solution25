<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use NMIPayment\Library\Constants\TransactionStatuses;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

/**
 * Refunds an order's NMI transaction once per refund source, capped at what NMI collected minus
 * what was already given back.
 *
 * A refund source is an order return, or a manual full refund of an order transaction. Each
 * source is claimed in the RefundLedger before the gateway is called, so a state that is entered
 * again, or two requests at once, cannot refund twice. The cap check and the claim run under a
 * per-order lock, so two returns of one order refunded at once cannot both fit under the same
 * balance.
 */
class NmiRefundService
{
    public const RESULT_REFUNDED = 'refunded';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_REFUSED = 'refused';
    public const RESULT_DECLINED = 'declined';
    public const RESULT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly NmiTransactionService $nmiTransactionService,
        private readonly NMIPaymentApiClient $nmiPaymentApiClient,
        private readonly NMIConfigService $nmiConfigService,
        private readonly RefundLedger $refundLedger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $claimKey the refund source (order return id, or order transaction id), a UUID
     * @param float|null $requestedAmount null refunds the whole remaining balance
     * @param string $payment NMI payment type of the original transaction: creditcard or check
     *
     * @return array{result: string, amount: float, fullyRefunded: bool}
     */
    public function refund(
        string $claimKey,
        string $orderId,
        string $salesChannelId,
        ?float $requestedAmount,
        string $payment,
        Context $context
    ): array {
        $outcome = static fn (string $result, float $amount = 0.0, bool $fully = false): array => [
            'result' => $result,
            'amount' => $amount,
            'fullyRefunded' => $fully,
        ];

        /** @var NmiTransactionEntity|null $transaction */
        $transaction = $this->nmiTransactionService->getTransactionByOrderId($orderId, $context);
        if ($transaction === null || $transaction->getTransactionId() === '') {
            $this->logger->info('[refund] no NMI transaction on the order, nothing to refund', ['orderId' => $orderId]);

            return $outcome(self::RESULT_SKIPPED);
        }
        $nmiTransactionId = $transaction->getTransactionId();

        $this->nmiPaymentApiClient->initializeForSalesChannel($salesChannelId);
        $amounts = $this->nmiPaymentApiClient->queryTransactionAmounts($nmiTransactionId);
        if (!$amounts['resolved'] || !$amounts['found']) {
            $this->logger->error('[refund] could not confirm the refundable amount with NMI, refusing', [
                'orderId' => $orderId,
                'claimKey' => $claimKey,
                'transactionId' => $nmiTransactionId,
                'resolved' => $amounts['resolved'],
            ]);

            return $outcome(self::RESULT_REFUSED);
        }

        $captured = (float) $amounts['capturedAmount'];

        if (!$this->refundLedger->lockOrder($orderId)) {
            $this->logger->warning('[refund] another refund for the order is in progress, refusing', [
                'orderId' => $orderId,
                'claimKey' => $claimKey,
            ]);

            return $outcome(self::RESULT_REFUSED);
        }

        try {
            $ledger = $this->refundLedger->totalsForOrder($orderId);
            $alreadyRefunded = max((float) $amounts['refundedAmount'], $ledger['succeeded']) + $ledger['pending'];
            $balanceCents = self::toCents($captured) - self::toCents($alreadyRefunded);

            $amount = $requestedAmount ?? $balanceCents / 100;
            if (self::toCents($amount) <= 0) {
                $this->logger->info('[refund] nothing left to refund', [
                    'orderId' => $orderId,
                    'claimKey' => $claimKey,
                    'capturedAmount' => $captured,
                    'alreadyRefunded' => $alreadyRefunded,
                ]);

                return $outcome(self::RESULT_SKIPPED);
            }

            if (self::toCents($amount) > $balanceCents) {
                $this->logger->warning('[refund] amount exceeds the refundable balance, refusing', [
                    'orderId' => $orderId,
                    'claimKey' => $claimKey,
                    'transactionId' => $nmiTransactionId,
                    'amount' => $amount,
                    'capturedAmount' => $captured,
                    'alreadyRefunded' => $alreadyRefunded,
                ]);

                return $outcome(self::RESULT_REFUSED);
            }

            if (!$this->refundLedger->claim($claimKey, $orderId, $amount)) {
                $this->logger->info('[refund] already refunded or in progress for this source', [
                    'orderId' => $orderId,
                    'claimKey' => $claimKey,
                ]);

                return $outcome(self::RESULT_SKIPPED);
            }
        } finally {
            $this->refundLedger->unlockOrder($orderId);
        }

        $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
        $securityKey = $this->nmiConfigService->getConfig($mode === 'live' ? 'privateKeyApiLive' : 'privateKeyApi', $salesChannelId);

        try {
            $response = $this->nmiPaymentApiClient->createTransaction([
                'security_key' => $securityKey,
                'type' => 'refund',
                'transactionid' => $nmiTransactionId,
                'payment' => $payment,
                'amount' => number_format($amount, 2, '.', ''),
            ]);
        } catch (\Throwable $e) {
            $response = null;
            $this->logger->error('[refund] NMI refund call threw', [
                'claimKey' => $claimKey,
                'exception_class' => $e::class,
            ]);
        }

        if (!\is_array($response) || !isset($response['response'])) {
            $this->logger->error('[refund] NMI refund outcome unknown, claim left pending for a manual check', [
                'orderId' => $orderId,
                'claimKey' => $claimKey,
                'transactionId' => $nmiTransactionId,
                'amount' => $amount,
            ]);

            return $outcome(self::RESULT_UNKNOWN, $amount);
        }

        if ($response['response'] !== '1') {
            $this->refundLedger->release($claimKey);
            $this->logger->warning('[refund] NMI declined the refund', [
                'orderId' => $orderId,
                'claimKey' => $claimKey,
                'transactionId' => $nmiTransactionId,
                'response_code' => $response['response'],
                'response_text' => $response['responsetext'] ?? null,
            ]);

            return $outcome(self::RESULT_DECLINED, $amount);
        }

        $refundTransactionId = isset($response['transactionid']) ? (string) $response['transactionid'] : null;

        $this->logger->info('[refund] refunded', [
            'orderId' => $orderId,
            'claimKey' => $claimKey,
            'transactionId' => $nmiTransactionId,
            'refundTransactionId' => $refundTransactionId,
            'amount' => $amount,
        ]);

        try {
            $this->refundLedger->markSucceeded($claimKey, $refundTransactionId);
        } catch (\Throwable $e) {
            $this->logger->error('[refund] refunded at NMI but the ledger was not updated, claim left pending', [
                'orderId' => $orderId,
                'claimKey' => $claimKey,
                'refundTransactionId' => $refundTransactionId,
                'exception_class' => $e::class,
            ]);
        }

        try {
            $this->nmiTransactionService->updateTransactionStatus($orderId, TransactionStatuses::REFUND->value, $context);
        } catch (\Throwable $e) {
            $this->logger->warning('[refund] refunded at NMI but the NMI transaction status was not updated', [
                'orderId' => $orderId,
                'exception_class' => $e::class,
            ]);
        }

        $fullyRefunded = self::toCents($alreadyRefunded) + self::toCents($amount) >= self::toCents($captured);

        return $outcome(self::RESULT_REFUNDED, $amount, $fullyRefunded);
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
