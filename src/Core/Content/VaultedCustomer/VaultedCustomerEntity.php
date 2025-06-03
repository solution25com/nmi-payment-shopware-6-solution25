<?php declare(strict_types=1);

namespace NMIPayment\Core\Content\VaultedCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class VaultedCustomerEntity extends Entity
{
  use EntityIdTrait;

  protected $id;

  protected ?String $customerId;

  protected  $vaultedCustomerId;

  protected  $cardType;

  protected  $billingId;
  protected $defaultBilling;

  public function getId(): string
  {
    return $this->id;
  }

  public function setId(string $id): void{
    $this->id = $id;
  }

  public function getCustomerId(): ?String{
    return $this->customerId;
  }

  public function setCustomerId(?String $customerId): void{
    $this->customerId = $customerId;
  }

  public function getVaultedCustomerId(){
    return $this->vaultedCustomerId;
  }

  public function setVaultedCustomerId(string $vaultedCustomerId): void{
    $this->vaultedCustomerId = $vaultedCustomerId;
  }

  public function getCardType(){
    return $this->cardType;
  }
  public function setCardType(string $cardType): void{
    $this->cardType = $cardType;
  }

  public function getBillingId(){
    return $this->billingId;
  }
  public function setBillingId(string $billingId): void{
    $this->billingId = $billingId;
  }

  public function getDefaultBilling(){
    return $this->defaultBilling;
  }

  public function setDefaultBilling(string $defaultBilling): void{
    $this->defaultBilling = $defaultBilling;
  }

}
