<?php

namespace NMIPayment\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class NMIConfigService
{
    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getConfig(string $configName, ?string $salesChannelId = null): mixed
    {
        return $this->systemConfigService->get('NMIPayment.config.' . trim($configName), $salesChannelId);
    }

    public function getModeConfig(string $salesChannelId): array
    {
        $mode = $this->getConfig('mode', $salesChannelId);

        return match ($mode) {
            'live' => [
            'publicKey' => $this->getConfig('publicKeyApiLive', $salesChannelId),
            'checkoutKey' => $this->getConfig('gatewayJsLive', $salesChannelId),
            ],
            'sandbox' => [
            'publicKey' => $this->getConfig('publicKeyApi', $salesChannelId),
            'checkoutKey' => $this->getConfig('gatewayJs', $salesChannelId),
            ],
            default => [],
        };
    }
}
