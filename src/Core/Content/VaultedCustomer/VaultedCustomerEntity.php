<?php

declare(strict_types=1);

namespace NMIPayment\Core\Content\VaultedCustomer;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class VaultedCustomerEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $customerId;

    protected $vaultedCustomerId;

    protected $cardType;

    protected $billingId;
    protected $defaultBilling;

    protected ?CustomerEntity $customer = null;

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

    public function getCardType()
    {
        return $this->cardType;
    }
    public function setCardType(string $cardType): void
    {
        $this->cardType = $cardType;
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

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }
}
