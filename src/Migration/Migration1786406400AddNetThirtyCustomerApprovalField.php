<?php

declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1786406400AddNetThirtyCustomerApprovalField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1786406400;
    }

    public function update(Connection $connection): void
    {
        $setId = 'nmi_net30_customer_approval';
        $fieldId = 'nmi_net30_approved';
        $relationId = 'nmi_net30_customer_relation';

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO `custom_field_set` (`id`, `name`, `config`, `active`, `global`, `position`, `created_at`)
            SELECT UNHEX(MD5(:setId)), :setId, :config, 1, 0, 1, NOW(3)
            WHERE NOT EXISTS (
                SELECT 1 FROM `custom_field_set` WHERE `name` = :setId
            )
            SQL,
            [
                'setId' => $setId,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'NET 30 approval',
                        'de-DE' => 'NET 30 Freigabe',
                    ],
                ], JSON_THROW_ON_ERROR),
            ]
        );

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO `custom_field` (`id`, `name`, `type`, `config`, `active`, `set_id`, `created_at`)
            SELECT UNHEX(MD5(:fieldId)), :fieldId, 'bool', :config, 1, `id`, NOW(3)
            FROM `custom_field_set`
            WHERE `name` = :setId
            AND NOT EXISTS (
                SELECT 1 FROM `custom_field` WHERE `name` = :fieldId
            )
            LIMIT 1
            SQL,
            [
                'fieldId' => $fieldId,
                'setId' => $setId,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'Approved for NET 30',
                        'de-DE' => 'Für NET 30 freigegeben',
                    ],
                    'componentName' => 'sw-field',
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 1,
                ], JSON_THROW_ON_ERROR),
            ]
        );

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO `custom_field_set_relation` (`id`, `set_id`, `entity_name`, `created_at`)
            SELECT UNHEX(MD5(:relationId)), `id`, 'customer', NOW(3)
            FROM `custom_field_set`
            WHERE `name` = :setId
            AND NOT EXISTS (
                SELECT 1 FROM `custom_field_set_relation`
                WHERE `set_id` = (SELECT `id` FROM `custom_field_set` WHERE `name` = :setId LIMIT 1)
                AND `entity_name` = 'customer'
            )
            LIMIT 1
            SQL,
            [
                'relationId' => $relationId,
                'setId' => $setId,
            ]
        );
    }
}
