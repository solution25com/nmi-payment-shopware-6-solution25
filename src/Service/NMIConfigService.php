<?php

namespace NMIPayment\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class NMIConfigService
{
    private SystemConfigService $systemConfigService;
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }
    public function getConfig(string $configName): mixed
    {
        return $this->systemConfigService->get('NMIPayment.config.' . trim($configName));
    }
}
