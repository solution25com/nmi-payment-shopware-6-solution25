<?php

declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Refund ledger: one row per refund source (an order return, or a manual full refund of an
 * order transaction), claimed before the gateway is called. The unique return_id is what makes
 * a repeated or concurrent refund for the same source a no-op instead of a second refund. No
 * foreign keys: the rows are a money trail and must outlive a deleted order or return.
 *
 * Same class name and timestamp as the CDS project copy of this plugin, so a shop that already
 * ran it there does not run it twice.
 *
 * @internal
 */
class Migration1791371926NmiRefund extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791371926;
    }

    public function update(Connection $connection): void
    {
        $sql = /** @lang text */
          <<<SQL
        CREATE TABLE IF NOT EXISTS `nmi_refund` (
            `id` BINARY(16) NOT NULL,
            `return_id` BINARY(16) NOT NULL,
            `order_id` BINARY(16) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `status` VARCHAR(16) NOT NULL,
            `gateway_transaction_id` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq.nmi_refund.return_id` (`return_id`),
            KEY `idx.nmi_refund.order_id` (`order_id`)
        )
            ENGINE = InnoDB
            DEFAULT CHARSET = utf8mb4
            COLLATE = utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }
}
