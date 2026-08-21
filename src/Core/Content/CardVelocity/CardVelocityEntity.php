<?php declare(strict_types=1);

namespace NMIPayment\Core\Content\CardVelocity;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class CardVelocityEntity extends Entity
{
    use EntityIdTrait;

    protected string $customerId;
    protected ?string $salesChannelId = null;
    protected ?array $fingerprints = null;
    protected int $distinctCards = 0;
    protected int $blockedAttempts = 0;
    protected ?\DateTimeInterface $lastBlockedAt = null;
    protected ?CustomerEntity $customer = null;
    protected ?SalesChannelEntity $salesChannel = null;

    public function getCustomerId(): string { return $this->customerId; }
    public function setCustomerId(string $customerId): void { $this->customerId = $customerId; }
    public function getSalesChannelId(): ?string { return $this->salesChannelId; }
    public function setSalesChannelId(?string $salesChannelId): void { $this->salesChannelId = $salesChannelId; }
    public function getFingerprints(): ?array { return $this->fingerprints; }
    public function setFingerprints(?array $fingerprints): void { $this->fingerprints = $fingerprints; }
    public function getDistinctCards(): int { return $this->distinctCards; }
    public function setDistinctCards(int $distinctCards): void { $this->distinctCards = $distinctCards; }
    public function getBlockedAttempts(): int { return $this->blockedAttempts; }
    public function setBlockedAttempts(int $blockedAttempts): void { $this->blockedAttempts = $blockedAttempts; }
    public function getLastBlockedAt(): ?\DateTimeInterface { return $this->lastBlockedAt; }
    public function setLastBlockedAt(?\DateTimeInterface $lastBlockedAt): void { $this->lastBlockedAt = $lastBlockedAt; }
    public function getCustomer(): ?CustomerEntity { return $this->customer; }
    public function setCustomer(?CustomerEntity $customer): void { $this->customer = $customer; }
    public function getSalesChannel(): ?SalesChannelEntity { return $this->salesChannel; }
    public function setSalesChannel(?SalesChannelEntity $salesChannel): void { $this->salesChannel = $salesChannel; }
}
