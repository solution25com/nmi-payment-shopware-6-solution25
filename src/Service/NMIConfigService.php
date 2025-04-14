<?php declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class NMIConfigService
{
    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getConfig(string $configName): mixed
    {
        return $this->systemConfigService->get('NMIPayment.config.' . trim($configName));
    }
}
