<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class NetThirtyCustomerEligibilityService
{
    public function __construct(
        private readonly DealerConfigService $dealerConfigService,
        private readonly EntityRepository $customerRepository
    ) {
    }

    public function isCustomerEligible(CustomerEntity $customer, ?string $salesChannelId = null): bool
    {
        if ($customer->getGuest()) {
            return false;
        }

        $customFields = $customer->getCustomFields() ?? [];
        if (($customFields[NetThirtyFields::CUSTOMER_APPROVED] ?? false) !== true) {
            return false;
        }

        $allowedGroups = $this->dealerConfigService->getNet30CustomerGroups($salesChannelId);
        if ($allowedGroups === []) {
            return true;
        }

        return in_array($customer->getGroupId(), $allowedGroups, true);
    }

    public function isCustomerIdEligible(string $customerId, ?string $salesChannelId, Context $context): bool
    {
        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context)->first();

        return $customer instanceof CustomerEntity
            && $this->isCustomerEligible($customer, $salesChannelId);
    }
}
