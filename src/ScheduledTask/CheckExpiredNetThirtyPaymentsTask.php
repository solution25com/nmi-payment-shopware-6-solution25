<?php

declare(strict_types=1);

namespace NMIPayment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CheckExpiredNetThirtyPaymentsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'dealer_payment_terms.check_expired_net_30_payments';
    }

    public static function getDefaultInterval(): int
    {
        return 10800;
    }
}
