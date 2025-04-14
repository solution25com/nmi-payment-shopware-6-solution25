<?php declare(strict_types=1);

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class VaultedCustomerService
{
    public function __construct(private readonly EntityRepository $vaultedCustomerRepository, private readonly LoggerInterface $logger)
    {
    }

    public function store(SalesChannelContext $salesChannelContext, string $vaultedShopperId, string $cardType, string $billingId, string $defaultBillingId): void
    {
        $context = $salesChannelContext->getContext();
        $salesChannelCustomerId = $salesChannelContext->getCustomer()->getId();

        try {
            $existingShopper = $this->vaultedCustomerRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('customerId', $salesChannelCustomerId)),
                $context
            )->first();

            if ($existingShopper) {
                $this->vaultedCustomerRepository->upsert(
                    [
                        [
                            'id' => $existingShopper->getId(),
                            'customerId' => $salesChannelCustomerId,
                            'vaultedCustomerId' => $vaultedShopperId,
                            'cardType' => $cardType,
                            'billingId' => $billingId,
                            'default_billing' => $billingId,
                            'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                        ],
                    ],
                    $context
                );
            } else {
                $this->vaultedCustomerRepository->upsert(
                    [
                        [
                            'id' => Uuid::randomHex(),
                            'customerId' => $salesChannelCustomerId,
                            'vaultedCustomerId' => $vaultedShopperId,
                            'cardType' => $cardType,
                            'billingId' => $billingId,
                            'defaultBilling' => $billingId,
                            'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                        ],
                    ],
                    $context
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error storing vaulted shopper data: ' . $e->getMessage());
        }
    }

    public function delete(SalesChannelContext $salesChannelContext, string $customerVaultId): void
    {
        $context = $salesChannelContext->getContext();

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $customerVaultId));
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->first();

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
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->first();

            if ($existingShopper) {
                $billingJson = $existingShopper->getBillingId();
                $billingArray = json_decode((string) $billingJson, true);

                if (\is_array($billingArray)) {
                    $updatedBillingArray = array_filter($billingArray, fn ($billing): bool => $billing['billingId'] !== $billingId);

                    $updatedBillingArray = array_values($updatedBillingArray);

                    $this->vaultedCustomerRepository->update(
                        [
                            [
                                'id' => $existingShopper->getId(),
                                'billingId' => json_encode($updatedBillingArray),
                            ],
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
            $existingShopper = $this->vaultedCustomerRepository->search($criteria, $context)->first();

            if ($existingShopper) {
                $billingJson = $existingShopper->getBillingId();
                $billingArray = json_decode((string) $billingJson, true);

                $selectedBilling = null;
                if (\is_array($billingArray)) {
                    foreach ($billingArray as $billing) {
                        if ($billing['billingId'] === $billingId) {
                            $selectedBilling = $billing;

                            break;
                        }
                    }
                }

                if ($selectedBilling) {
                    $this->logger->info('Selected Billing: ' . json_encode($selectedBilling));
                    $this->vaultedCustomerRepository->update(
                        [
                            [
                                'id' => $existingShopper->getId(),

                                'defaultBilling' => json_encode([$selectedBilling]),
                                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
                            ],
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
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $res = $this->vaultedCustomerRepository->search($criteria, $context)->first();

        return $res ? $res->getVaultedCustomerId() : null;
    }

    public function vaultedCustomerExist(Context $context, string $customerId): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $vaultedShopper = $this->vaultedCustomerRepository->search($criteria, $context)->first();

        return $vaultedShopper !== null;
    }

    public function getBillingIdFromCustomerId(Context $context, string $customerId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $vaultedShopper = $this->vaultedCustomerRepository->search($criteria, $context)->first();
        if ($vaultedShopper) {
            return $vaultedShopper->getBillingId();
        }

        return null;
    }

    public function getBillingIdByVaultedId(Context $context, string $vaultedShopperId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedShopperId));
        $billingId = $this->vaultedCustomerRepository->search($criteria, $context)->first();
        if ($billingId) {
            return $billingId;
        }

        return null;
    }

    public function getDefaultBillingId(Context $context, string $vaultedShopperId)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedShopperId));
        $defaultBilling = $this->vaultedCustomerRepository->search($criteria, $context)->first();
        if ($defaultBilling) {
            return $defaultBilling->getDefaultBilling();
        }

        return null;
    }

    /**
     * @return array{vaultedCustomerId: mixed, billingId: mixed, lastDigits: mixed, firstName: mixed, isDefault: false}[]
     */
    public function dropdownCards(Context $context, string $customerId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $savedCards = $this->vaultedCustomerRepository->search($criteria, $context)->getElements();
        $defaultBilling = null;

        $formattedCards = [];

        foreach ($savedCards as $card) {
            $billingData = json_decode((string) $card->getBillingId(), true);
            if (\is_array($billingData)) {
                foreach ($billingData as $billingEntry) {
                    $firstSixDigits = substr((string) $billingEntry['lastDigits'], 0, 6);

                    $formattedCards[] = [
                        'vaultedCustomerId' => $card->getVaultedCustomerId(),
                        'billingId' => $billingEntry['billingId'],
                        'lastDigits' => $billingEntry['lastDigits'] ?? 'XXXX',
                        'firstName' => $billingEntry['firstName'] ?? 'Unknown',
                        'isDefault' => ($defaultBilling && $defaultBilling['billingId'] === $billingEntry['billingId']),
                    ];
                }
            }
        }

        return $formattedCards;
    }
}
