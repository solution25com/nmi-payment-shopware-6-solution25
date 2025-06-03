<?php declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
#[Package('core')]
class Migration1744013309AlterSelectedBillingId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1744013309;
    }

  public function update(Connection $connection): void
  {

    $sqlAlter = /** @lang text */
      <<<SQL
        ALTER TABLE `nmi_transaction`
            ADD COLUMN `selectedBillingId` VARCHAR(255) DEFAULT NULL;
        SQL;

    $connection->executeStatement($sqlAlter);
  }
}
