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
     * Returns null when the provided vault ID is ownedd by the current customer.
     * Returns a 403/400 JsonResponse when ownership cannot be confirmed.
     */
    public function assertVaultOwnership(string $customerVaultId, SalesChannelContext $context): ?JsonResponse
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return $this->deny('Authentication required.', Response::HTTP_FORBIDDEN);
        }

        if ($customerVaultId === '') {
            return $this->deny('Missing vault ID.', Response::HTTP_BAD_REQUEST);
        }

        $ownedVaultId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId(
            $context->getContext(),
            $customer->getId()
        );

        if ($ownedVaultId === null || !hash_equals($ownedVaultId, $customerVaultId)) {
            $this->logger->warning('Vault ownership check failed', [
                'customer_id' => $customer->getId(),
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
