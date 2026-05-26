<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Installer\OrderStateInstaller;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NetThirtyOrderAuthorizationService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly VaultedCustomerService $vaultedCustomerService
    ) {
    }

    public function isOrderOwnedByCustomer(OrderEntity $order, string $customerId): bool
    {
        return $order->getOrderCustomer()?->getCustomerId() === $customerId;
    }

    /**
     * @param array<int, string> $orderIds
     * @return array<int, string>
     */
    public function filterOwnedUnpaidNet30OrderIds(
        array $orderIds,
        string $customerId,
        SalesChannelContext $context
    ): array {
        $criteria = new Criteria($orderIds);
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('orderCustomer');

        $orders = $this->orderRepository->search($criteria, $context->getContext())->getEntities();
        $valid = [];

        foreach ($orders as $order) {
            if (!$this->isOrderOwnedByCustomer($order, $customerId)) {
                continue;
            }

            $state = $order->getTransactions()?->first()?->getStateMachineState()?->getTechnicalName();
            if (
                $state !== OrderStateInstaller::STATE_TECHNICAL_NAME
                && $state !== OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
            ) {
                continue;
            }

            $customFields = $order->getCustomFields() ?? [];
            if (($customFields[NetThirtyFields::DEALER_PAYMENT_TYPE] ?? null) !== NetThirtyFields::DEALER_PAYMENT_TYPE_NET30) {
                continue;
            }

            $valid[] = $order->getId();
        }

        return $valid;
    }

    public function isAllowedVaultProfile(
        string $customerId,
        string $customerVaultId,
        string $billingId,
        SalesChannelContext $context
    ): bool {
        $ownedVaultId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context->getContext(), $customerId);
        if ($ownedVaultId === null || !hash_equals($ownedVaultId, $customerVaultId)) {
            return false;
        }

        $savedCards = $this->vaultedCustomerService->dropdownCards($context->getContext(), $customerId);
        foreach ($savedCards as $card) {
            if (
                ($card['vaultedCustomerId'] ?? null) === $customerVaultId
                && ($card['billingId'] ?? null) === $billingId
            ) {
                return true;
            }
        }

        return false;
    }
}
