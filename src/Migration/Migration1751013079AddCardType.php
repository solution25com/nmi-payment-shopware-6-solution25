<?php declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1751013079AddCardType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1751013079;
    }

    public function update(Connection $connection): void
    {
        $schemaManager = method_exists($connection, 'createSchemaManager')
            ? $connection->createSchemaManager()
            : $connection->getSchemaManager();

        $columns = $schemaManager->listTableColumns('nmi_vaulted_customer');

        if (!array_key_exists('card_type', $columns)) {
            $connection->executeStatement('
            ALTER TABLE `nmi_vaulted_customer`
            ADD COLUMN `card_type` VARCHAR(255) DEFAULT NULL AFTER `vaulted_customer_id`
        ');
        }
    }
}
