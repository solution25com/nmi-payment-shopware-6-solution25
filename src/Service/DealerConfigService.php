<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class DealerConfigService
{
    public const CONFIG_PREFIX = 'NMIPayment.config.';

    public const CONFIG_KEY_NET30_CUSTOMER_GROUPS = self::CONFIG_PREFIX . 'net30CustomerGroups';
    public const CONFIG_KEY_DEALER_CUSTOMER_GROUPS = self::CONFIG_PREFIX . 'dealerCustomerGroups';
    public const CONFIG_KEY_DISABLE_CC_PAYMENT = self::CONFIG_PREFIX . 'disableCreditCardPayment';
    public const CONFIG_KEY_AUTO_GENERATE_INVOICE = self::CONFIG_PREFIX . 'autoGenerateInvoice';
    public const CONFIG_KEY_SKIP_SENT_FILTER_FOR_DEALERS = self::CONFIG_PREFIX . 'skipSentFilterForDealers';
    public const CONFIG_KEY_SKIP_SENT_FILTER_FOR_NET30 = self::CONFIG_PREFIX . 'skipSentFilterForNet30';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * @return string[]
     */
    public function getNet30CustomerGroups(?string $salesChannelId = null): array
    {
        $groups = $this->systemConfigService->get(self::CONFIG_KEY_NET30_CUSTOMER_GROUPS, $salesChannelId) ?? [];

        return is_array($groups) ? $groups : [];
    }

    public function isCustomerGroupAllowedForNet30(string $groupId, ?string $salesChannelId = null): bool
    {
        $allowed = $this->getNet30CustomerGroups($salesChannelId);

        // Empty list = no restriction, all groups may see NET 30
        if (empty($allowed)) {
            return true;
        }

        return in_array($groupId, $allowed, true);
    }

    /**
     * @return string[]
     */
    public function getDealerCustomerGroups(): array
    {
        $groups = $this->systemConfigService->get(self::CONFIG_KEY_DEALER_CUSTOMER_GROUPS) ?? [];

        return is_array($groups) ? $groups : [];
    }

    public function isDealerCustomerGroup(string $groupId): bool
    {
        return in_array($groupId, $this->getDealerCustomerGroups(), true);
    }

    public function isSkipSentFilterForDealers(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_KEY_SKIP_SENT_FILTER_FOR_DEALERS);
    }

    public function isSkipSentFilterForNet30(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_KEY_SKIP_SENT_FILTER_FOR_NET30);
    }

    public function isCreditCardPaymentDisabled(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_KEY_DISABLE_CC_PAYMENT);
    }

    public function isAutoInvoiceGenerationEnabled(): bool
    {
        return (bool) ($this->systemConfigService->get(self::CONFIG_KEY_AUTO_GENERATE_INVOICE) ?? true);
    }
}
