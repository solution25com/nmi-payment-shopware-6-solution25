<?php

namespace NMIPayment\Service;

use NMIPayment\Core\Content\VaultedCustomer\VaultedCustomerEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class VaultedCustomerService
{
    /** @var EntityRepository<\NMIPayment\Core\Content\VaultedCustomer\VaultedCustomerCollection> */
    private EntityRepository $vaultedCustomerRepository;
    private LoggerInterface $logger;

    public function __construct(EntityRepository $vaultedShopperRepository, LoggerInterface $logger)
    {
        $this->vaultedCustomerRepository = $vaultedShopperRepository;
        $this->logger = $logger;
    }

    /**
     * Saves a vault's card list for the logged-in customer: one row per (customer, NMI vault).
     *
     * The row is found by customer AND vault id. Found by customer only, a customer with more than
     * one vault row had whichever row the database returned first rewritten with another vault's id
     * and cards. An existing row keeps its default card. A new row's new card becomes the default
     * only when the customer has none yet, so there is one default per customer across rows.
     *
     * @param string $billingId the vault's full card list (JSON), already merged with the new card by the caller
     * @param string $defaultBillingId the billing id of the card just added
     */
    public function store(SalesChannelContext $salesChannelContext, string $vaultedShopperId, string $cardType, string $billingId, string $defaultBillingId = ''): void
    {
        $context = $salesChannelContext->getContext();
        $salesChannelCustomerId = $salesChannelContext->getCustomer()->getId();

        try {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('customerId', $salesChannelCustomerId))
                ->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedShopperId))
                ->setLimit(1);
            /** @var VaultedCustomerEntity|null $existingShopper */
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();

            if ($existingShopper) {
                $this->vaultedCustomerRepository->update([[
                    'id' => $existingShopper->getId(),
                    'cardType' => $cardType,
                    'billingId' => $billingId,
                    'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]], $context);
            } else {
                $this->vaultedCustomerRepository->create([[
                    'id' => Uuid::randomHex(),
                    'customerId' => $salesChannelCustomerId,
                    'vaultedCustomerId' => $vaultedShopperId,
                    'cardType' => $cardType,
                    'billingId' => $billingId,
                    'defaultBilling' => $this->customerHasDefaultCard($context, $salesChannelCustomerId)
                        ? null
                        : $this->defaultEntry($billingId, $defaultBillingId),
                    'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]], $context);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error storing vaulted shopper data', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'customerId' => $salesChannelCustomerId,
                'vaultedShopperId' => $vaultedShopperId,
            ]);
        }
    }

    /**
     * "The customer's vault" for callers that need one: always the oldest row, so the vault id and
     * the card list read by separate calls come from the same row.
     */
    private function firstRowCriteria(string $customerId): Criteria
    {
        return (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING), new FieldSorting('id', FieldSorting::ASCENDING))
            ->setLimit(1);
    }

    private function customerHasDefaultCard(Context $context, string $customerId): bool
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('defaultBilling', null)]))
            ->setLimit(1);

        return $this->vaultedCustomerRepository->searchIds($criteria, $context)->firstId() !== null;
    }

    /**
     * The default_billing value for a new row: a one-entry list holding the new card (the column is
     * varchar(255), so never the vault's whole list). Null when the list is unreadable.
     */
    private function defaultEntry(string $billingJson, string $defaultBillingId): ?string
    {
        $cards = json_decode($billingJson, true);
        if (!\is_array($cards) || $cards === []) {
            return null;
        }

        foreach ($cards as $card) {
            if (\is_array($card) && ($card['billingId'] ?? null) === $defaultBillingId) {
                return (string) json_encode([$card]);
            }
        }

        $last = end($cards);

        return \is_array($last) ? (string) json_encode([$last]) : null;
    }

    public function delete(SalesChannelContext $salesChannelContext, string $customerVaultId): void
    {
        $context = $salesChannelContext->getContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $customerVaultId));
            $criteria->setLimit(1);
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
            /** @var VaultedCustomerEntity|null $existingShopper */

            if ($existingShopper) {
                $this->vaultedCustomerRepository->delete([['id' => $existingShopper->getId()]], $context);
                $this->logger->info('Successfully deleted vaulted customer data from the database.');
            } else {
                $this->logger->warning('No vaulted customer data found to delete for vault ID: ' . $customerVaultId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error deleting vaulted customer data: ' . $e->getMessage());
        }
    }

    public function deleteBillingFromDB(SalesChannelContext $salesChannelContext, string $customerVaultId, string $billingId): void
    {
        $context = $salesChannelContext->getContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $customerVaultId));
            $criteria->setLimit(1);
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
            /** @var VaultedCustomerEntity|null $existingShopper */

            if ($existingShopper) {
                $billingJson = $existingShopper->getBillingId();
                $billingArray = json_decode($billingJson, true);

                if (is_array($billingArray)) {
                    $updatedBillingArray = array_filter($billingArray, function ($billing) use ($billingId) {
                        return $billing['billingId'] !== $billingId;
                    });

                    $updatedBillingArray = array_values($updatedBillingArray);
                    /** @var VaultedCustomerEntity|null $existingShopper */

                    $this->vaultedCustomerRepository->update(
                        [
                        [
                        'id' => $existingShopper->getId(),
                        'billingId' => json_encode($updatedBillingArray)
                        ]
                        ],
                        $context
                    );

                    $this->logger->info('Successfully deleted billing data with billingId: ' . $billingId);
                } else {
                    $this->logger->warning('Invalid or empty billing data found for vaulted customer ID: ' . $customerVaultId);
                }
            } else {
                $this->logger->warning('No vaulted customer data found for vaulted ID: ' . $customerVaultId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error deleting billing data: ' . $e->getMessage());
        }
    }


    public function setDefaultBilling(SalesChannelContext $salesChannelContext, string $vaultedCustomerId, string $billingId): void
    {
        $context = $salesChannelContext->getContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedCustomerId));
            $criteria->addFilter(new EqualsFilter('customerId', $salesChannelContext->getCustomer()?->getId()));
            $criteria->setLimit(1);
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
            /** @var VaultedCustomerEntity|null $existingShopper */

            if ($existingShopper) {
                $billingJson = $existingShopper->getBillingId();
                $billingArray = json_decode($billingJson, true);

                $selectedBilling = null;
                if (is_array($billingArray)) {
                    foreach ($billingArray as $billing) {
                        if ($billing['billingId'] === $billingId) {
                            $selectedBilling = $billing;
                            break;
                        }
                    }
                }
                /** @var VaultedCustomerEntity|null $existingShopper */

                if ($selectedBilling) {
                    $this->deleteDefaultBilling($context, (string) $existingShopper->getCustomerId());
                    $this->vaultedCustomerRepository->update(
                        [
                        [
                        'id' => $existingShopper->getId(),

                        'defaultBilling' => json_encode([$selectedBilling]),
                        'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                        ]
                        ],
                        $context
                    );

                    $this->logger->info('Successfully updated default billing for vaulted customer: ' . $vaultedCustomerId);
                } else {
                    $this->logger->warning('No billing data found for billingId: ' . $billingId);
                }
            } else {
                $this->logger->warning('No vaulted customer data found for vaulted customer ID: ' . $vaultedCustomerId);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error billing: ' . $e->getMessage());
        }
    }
    public function getVaultedCustomerIdByCustomerId(Context $context, string $customerId): ?string
    {
        $criteria = $this->firstRowCriteria($customerId);
        /** @var VaultedCustomerEntity|null $res */
        $res = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
        return $res?->getVaultedCustomerId();
    }

    public function vaultedCustomerExist(Context $context, string $customerId): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->setLimit(1);

        return $this->vaultedCustomerRepository->searchIds($criteria, $context)->firstId() !== null;
    }
    public function getBillingIdFromCustomerId(Context $context, string $customerId)
    {
        $criteria = $this->firstRowCriteria($customerId);
        /** @var VaultedCustomerEntity|null $vaultedShopper */
        $vaultedShopper = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
        if ($vaultedShopper) {
            return $vaultedShopper->getBillingId();
        }
        return null;
    }

    /**
     * @param string|null $customerId scope to this customer's row; pass it when merging a new card into the list
     */
    public function getBillingIdByVaultedId(Context $context, string $vaultedShopperId, ?string $customerId = null)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedShopperId));
        if ($customerId !== null) {
            $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        }
        $criteria->setLimit(1);
        $billingId = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
        if ($billingId) {
            return $billingId;
        }
        return null;
    }

    /**
     * Ownership check for routes that act on a client-supplied vault id: true only when this
     * customer has a row for exactly that vault.
     */
    public function customerOwnsVault(Context $context, string $customerId, string $vaultedCustomerId): bool
    {
        if ($customerId === '' || $vaultedCustomerId === '') {
            return false;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedCustomerId));
        $criteria->setLimit(1);

        return $this->vaultedCustomerRepository->searchIds($criteria, $context)->firstId() !== null;
    }

    /**
     * True only when the card (billing id) is stored in this customer's own row for that vault.
     */
    public function customerOwnsCard(Context $context, string $customerId, string $vaultedCustomerId, string $billingId): bool
    {
        if ($customerId === '' || $vaultedCustomerId === '' || $billingId === '') {
            return false;
        }

        $row = $this->getBillingIdByVaultedId($context, $vaultedCustomerId, $customerId);
        if (!$row instanceof VaultedCustomerEntity) {
            return false;
        }

        return \in_array($billingId, self::billingIdsOf($row->getBillingId()), true);
    }

    /**
     * @return list<string>
     */
    public static function billingIdsOf(?string $billingJson): array
    {
        $entries = $billingJson !== null ? json_decode($billingJson, true) : null;
        if (!\is_array($entries)) {
            return [];
        }

        $ids = [];
        foreach ($entries as $entry) {
            if (\is_array($entry) && \is_scalar($entry['billingId'] ?? null) && (string) $entry['billingId'] !== '') {
                $ids[] = (string) $entry['billingId'];
            }
        }

        return $ids;
    }

    public function getDefaultBillingId(Context $context, string $vaultedShopperId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedShopperId));
        $criteria->setLimit(1);
        /** @var VaultedCustomerEntity|null $defaultBilling */
        $defaultBilling = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->first();
        return $defaultBilling?->getDefaultBilling();
    }

    public function dropdownCards(Context $context, string $customerId)
    {

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->setLimit(500);

        $savedCards = $this->vaultedCustomerRepository->search($criteria, $context)->getEntities()->getElements();
        $defaultBilling = $this->defaultCardOf($savedCards);

        $formattedCards = [];

        foreach ($savedCards as $card) {
            /** @var VaultedCustomerEntity|null $card */
            $billingData = json_decode($card->getBillingId(), true);

            if (is_array($billingData)) {
                foreach ($billingData as $billingEntry) {
                    $formattedCards[] = [
                      'vaultedCustomerId' => $card->getVaultedCustomerId(),
                      'billingId' => $billingEntry['billingId'],
                      'lastDigits' => $billingEntry['lastDigits'] ?? 'XXXX',
                      'firstName' => $billingEntry['firstName'] ?? 'Unknown',
                      'lastName' => $billingEntry['lastName'] ?? 'Unknown',
                      'cardType' => $billingEntry['cardType'],
                      'isDefault' => ($defaultBilling !== null && ($defaultBilling['billingId'] ?? null) === $billingEntry['billingId']),
                    ];
                }
            }
        }
        return $formattedCards;
    }

    /**
     * The customer's default card, read from whichever of their vault rows holds it.
     *
     * @param iterable<VaultedCustomerEntity> $rows
     *
     * @return array<string, mixed>|null
     */
    public function defaultCardOf(iterable $rows): ?array
    {
        foreach ($rows as $row) {
            $raw = $row->getDefaultBilling();
            if (!$raw) {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (\is_array($decoded) && \is_array($decoded[0] ?? null)) {
                return $decoded[0];
            }
        }

        return null;
    }

    /**
     * Clears the default flag on every vault row of the customer.
     */
    public function deleteDefaultBilling(Context $context, string $customerId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('defaultBilling', null)]));
        $criteria->setLimit(500);

        $ids = $this->vaultedCustomerRepository->searchIds($criteria, $context)->getIds();

        $updates = [];
        foreach ($ids as $id) {
            $updates[] = [
                'id' => $id,
                'defaultBilling' => null,
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ];
        }

        if ($updates !== []) {
            $this->vaultedCustomerRepository->update($updates, $context);
        }
    }
}
