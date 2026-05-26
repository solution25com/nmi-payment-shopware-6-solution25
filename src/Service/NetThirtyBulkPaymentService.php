<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NetThirtyBulkPaymentService
{
    public function __construct(
        private readonly VaultedCustomerService $vaultedCustomerService,
        private readonly ?NetThirtyBulkPaymentProcessor $processor = null,
        private readonly ?NetThirtyACHBulkPaymentProcessor $achProcessor = null
    ) {
    }

    public function customerHasSavedCards(string $customerId, Context $context): bool
    {
        return $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
    }

    public function hasProcessor(): bool
    {
        return $this->processor !== null || $this->achProcessor !== null;
    }

    public function processBulkPaymentWithSavedCard(
        string $customerVaultId,
        string $billingId,
        array $orderIds,
        Context $context
    ): array {
        if ($this->processor === null) {
            return [[
                'success' => false,
                'error' => 'Bulk payment processing is not available. The payment processor service is not configured.',
            ]];
        }

        return $this->processor->processBulkPaymentWithVaultedCard(
            $customerVaultId,
            $billingId,
            $orderIds,
            $context
        );
    }

    public function processBulkPaymentWithACH(
        array $paymentData,
        array $orderIds,
        SalesChannelContext $context
    ): array {
        if ($this->achProcessor === null) {
            return [[
                'success' => false,
                'error' => 'ACH bulk payment processing is not available. The ACH payment processor service is not configured.',
            ]];
        }

        return $this->achProcessor->processBulkPaymentWithACH(
            $paymentData,
            $orderIds,
            $context->getContext()
        );
    }
}
