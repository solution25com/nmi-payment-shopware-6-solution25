<?php declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1783361247AddAuthAmountToNmiTransaction extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1783361247;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'ALTER TABLE `nmi_transaction` ADD COLUMN IF NOT EXISTS `auth_amount` DOUBLE DEFAULT NULL'
        );
    }
}
