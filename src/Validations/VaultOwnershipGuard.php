<?php

declare(strict_types=1);

namespace NMIPayment\Validations;

use NMIPayment\Service\VaultedCustomerService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VaultOwnershipGuard
{
    public function __construct(
        private readonly VaultedCustomerService $vaultedCustomerService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns null when the vault (and, when given, the card) belongs to the current customer.
     * Returns a 403/400 JsonResponse when ownership cannot be confirmed. "Not yours" and "does not
     * exist" get the same answer, so the route cannot be used to discover valid vault ids.
     *
     * The check matches the customer's row for exactly this vault. Comparing against "the
     * customer's first vault row" let a vault id written onto the customer's account by a client
     * pass as their own.
     */
    public function assertVaultOwnership(mixed $customerVaultId, SalesChannelContext $context, mixed $billingId = null): ?JsonResponse
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return $this->deny('Authentication required.', Response::HTTP_FORBIDDEN);
        }

        if (!\is_scalar($customerVaultId) || (string) $customerVaultId === '') {
            return $this->deny('Missing vault ID.', Response::HTTP_BAD_REQUEST);
        }

        $customerVaultId = (string) $customerVaultId;
        $owned = $billingId === null
            ? $this->vaultedCustomerService->customerOwnsVault($context->getContext(), $customer->getId(), $customerVaultId)
            : \is_scalar($billingId)
                && $this->vaultedCustomerService->customerOwnsCard($context->getContext(), $customer->getId(), $customerVaultId, (string) $billingId);

        if (!$owned) {
            $this->logger->warning('Vault ownership check failed', [
                'customer_id' => $customer->getId(),
                'sales_channel_id' => $context->getSalesChannelId(),
            ]);

            return $this->deny('Invalid vault profile.', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
