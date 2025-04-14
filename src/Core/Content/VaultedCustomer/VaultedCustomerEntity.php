<?php

declare(strict_types=1);

namespace NMIPayment\Core\Content\VaultedCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class VaultedCustomerEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId = null;

    protected $vaultedCustomerId;

    protected $billingId;

    protected $defaultBilling;

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getVaultedCustomerId()
    {
        return $this->vaultedCustomerId;
    }

    public function setVaultedCustomerId(string $vaultedCustomerId): void
    {
        $this->vaultedCustomerId = $vaultedCustomerId;
    }

    public function getBillingId()
    {
        return $this->billingId;
    }

    public function setBillingId(string $billingId): void
    {
        $this->billingId = $billingId;
    }

    public function getDefaultBilling()
    {
        return $this->defaultBilling;
    }

    public function setDefaultBilling(string $defaultBilling): void
    {
        $this->defaultBilling = $defaultBilling;
    }
}
