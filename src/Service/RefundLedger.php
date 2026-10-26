<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Refund ledger. Each refund source (an order return, or a manual full refund of an order
 * transaction) may be refunded once: the row is claimed (status pending) before the gateway is
 * called, so a repeated state change or a concurrent request finds the claim and stops.
 *
 * A gateway decline releases the claim so the refund can be retried. A gateway call whose
 * outcome is unknown (timeout, exception) keeps it pending: retrying blind could refund twice,
 * so someone has to check NMI and resolve the row by hand.
 */
class RefundLedger
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    private const LOCK_PREFIX = 'nmi_refund_';

    private const LOCK_TIMEOUT_S = 10;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return bool false when the return already has a claim (refunded or in flight)
     */
    public function claim(string $returnId, string $orderId, float $amount): bool
    {
        try {
            $this->connection->insert('nmi_refund', [
                'id' => Uuid::randomBytes(),
                'return_id' => Uuid::fromHexToBytes($returnId),
                'order_id' => Uuid::fromHexToBytes($orderId),
                'amount' => number_format($amount, 2, '.', ''),
                'status' => self::STATUS_PENDING,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function markSucceeded(string $returnId, ?string $gatewayTransactionId): void
    {
        $this->connection->update('nmi_refund', [
            'status' => self::STATUS_SUCCEEDED,
            'gateway_transaction_id' => $gatewayTransactionId,
            'updated_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ], ['return_id' => Uuid::fromHexToBytes($returnId)]);
    }

    public function release(string $returnId): void
    {
        $this->connection->delete('nmi_refund', [
            'return_id' => Uuid::fromHexToBytes($returnId),
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Serialises the cap check and the claim for one order. Without it, two returns of the
     * same order refunded at once both pass the cap before either claim exists.
     *
     * @return bool false when the lock could not be taken within the timeout
     */
    public function lockOrder(string $orderId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT GET_LOCK(:name, :timeout)',
            ['name' => self::LOCK_PREFIX . $orderId, 'timeout' => self::LOCK_TIMEOUT_S]
        ) === 1;
    }

    public function unlockOrder(string $orderId): void
    {
        $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK_PREFIX . $orderId]);
    }

    /**
     * What this ledger holds for the order: refunds NMI confirmed, and claims whose gateway
     * call is in flight or ended with an unknown outcome.
     *
     * @return array{succeeded: float, pending: float}
     */
    public function totalsForOrder(string $orderId): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT `status`, COALESCE(SUM(`amount`), 0) FROM `nmi_refund` WHERE `order_id` = :orderId GROUP BY `status`',
            ['orderId' => Uuid::fromHexToBytes($orderId)]
        );

        return [
            'succeeded' => (float) ($rows[self::STATUS_SUCCEEDED] ?? 0),
            'pending' => (float) ($rows[self::STATUS_PENDING] ?? 0),
        ];
    }
}
