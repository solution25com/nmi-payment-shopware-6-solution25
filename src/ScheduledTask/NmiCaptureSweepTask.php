<?php declare(strict_types=1);

namespace NMIPayment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class NmiCaptureSweepTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'nmi.capture_sweep';
    }

    public static function getDefaultInterval(): int
    {
        return 600;
    }
}
