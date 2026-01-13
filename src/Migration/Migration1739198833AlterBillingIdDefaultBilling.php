<?php

declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('checkout')]
class Migration1739198833AlterBillingIdDefaultBilling extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1739198833;
    }

    public function update(Connection $connection): void
    {

        $sqlAlter = /** @lang text */
        <<<SQL
        ALTER TABLE `nmi_vaulted_customer`
            ADD COLUMN `billingId` LONGTEXT DEFAULT NULL,
            ADD COLUMN `default_billing` VARCHAR(255) DEFAULT NULL;
        SQL;

        $connection->executeStatement($sqlAlter);
    }
}
