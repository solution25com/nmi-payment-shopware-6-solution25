<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DealerDocumentCriteriaService
{
    public function __construct(
        private readonly DealerConfigService $configService
    ) {
    }

    public function adjustDocumentCriteria(Criteria $criteria, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return;
        }

        if (!$this->shouldSkipSentFilter($customer->getGroupId())) {
            return;
        }

        $documentsCriteria = $criteria->getAssociation('documents');
        $documentsCriteria->resetFilters();
        $documentsCriteria->addFilter(new EqualsFilter('config.displayInCustomerAccount', 'true'));
    }

    private function shouldSkipSentFilter(string $customerGroupId): bool
    {
        if (
            $this->configService->isSkipSentFilterForDealers()
            && $this->configService->isDealerCustomerGroup($customerGroupId)
        ) {
            return true;
        }

        if ($this->configService->isSkipSentFilterForNet30()) {
            return true;
        }

        return false;
    }
}
