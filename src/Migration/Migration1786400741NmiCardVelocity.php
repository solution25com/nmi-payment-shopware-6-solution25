<?php declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1786400741NmiCardVelocity extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1786400741;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
CREATE TABLE IF NOT EXISTS `nmi_card_velocity` (
    `id` BINARY(16) NOT NULL,
    `customer_id` BINARY(16) NOT NULL,
    `sales_channel_id` BINARY(16) DEFAULT NULL,
    `fingerprints` JSON DEFAULT NULL,
    `distinct_cards` INT UNSIGNED NOT NULL DEFAULT 0,
    `blocked_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_blocked_at` DATETIME(3) DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.nmi_card_velocity.customer_id` (`customer_id`),
    KEY `idx.nmi_card_velocity.blocked_attempts` (`blocked_attempts`),
    CONSTRAINT `fk.nmi_card_velocity.customer_id` FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk.nmi_card_velocity.sales_channel_id` FOREIGN KEY (`sales_channel_id`)
        REFERENCES `sales_channel` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
}
