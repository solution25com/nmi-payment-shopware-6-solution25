<?php declare(strict_types=1);

namespace NMIPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1782500766NmiPaymentMethodTechnicalName extends MigrationStep
{
    private const TECHNICAL_NAMES = [
        'NMIPayment\\Gateways\\CreditCard' => 'nmi_credit_card',
        'NMIPayment\\Gateways\\AchEcheck' => 'nmi_ach_echeck',
        'NMIPayment\\Gateways\\Net30' => 'NMI-NET30',
    ];

    public function getCreationTimestamp(): int
    {
        return 1782500766;
    }

    public function update(Connection $connection): void
    {
        foreach (self::TECHNICAL_NAMES as $handlerIdentifier => $technicalName) {
            $connection->executeStatement(
                'UPDATE `payment_method`
                    SET `technical_name` = :technicalName, `updated_at` = NOW()
                  WHERE `handler_identifier` = :handlerIdentifier
                    AND (`technical_name` IS NULL OR `technical_name` = \'\')',
                ['technicalName' => $technicalName, 'handlerIdentifier' => $handlerIdentifier]
            );
        }
    }
}
