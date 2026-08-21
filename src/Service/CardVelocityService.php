<?php declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Core\Content\CardVelocity\CardVelocityEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class CardVelocityService
{
    private const DEFAULT_WINDOW_HOURS = 24;
    private const DEFAULT_MAX_DISTINCT_CARDS = 3;

    public function __construct(
        private readonly EntityRepository $cardVelocityRepository,
        private readonly NMIConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?string $salesChannelId): bool
    {
        return (bool) ($this->configService->getConfig('cardVelocityEnabled', $salesChannelId) ?? true);
    }

    public function registerAttempt(
        string $customerId,
        ?string $salesChannelId,
        string $fingerprint,
        Context $context
    ): bool {
        try {
            return $this->evaluateAttempt($customerId, $salesChannelId, $fingerprint, $context);
        } catch (\Throwable $exception) {
            $this->logger->warning('[card-velocity] retrying after write failure', [
                'error' => $exception->getMessage(),
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
            ]);

            return $this->evaluateAttempt($customerId, $salesChannelId, $fingerprint, $context);
        }
    }

    private function evaluateAttempt(
        string $customerId,
        ?string $salesChannelId,
        string $fingerprint,
        Context $context
    ): bool {
        $now = new \DateTimeImmutable();
        $existing = $this->fetchRow($customerId, $context);
        $stored = $existing?->getFingerprints() ?? [];
        $active = $this->pruneExpired($stored, $now, $this->windowHours($salesChannelId));
        $allowedFingerprints = array_column(
            array_filter($active, static fn (array $entry): bool => empty($entry['blocked'])),
            'fp'
        );

        if (in_array($fingerprint, $allowedFingerprints, true)) {
            if ($existing === null || $active !== $stored) {
                $this->persist($existing, $customerId, $salesChannelId, $active, $context);
            }

            return true;
        }

        if (count($allowedFingerprints) >= $this->maxDistinctCards($salesChannelId)) {
            $blockedFingerprints = array_column(
                array_filter($active, static fn (array $entry): bool => !empty($entry['blocked'])),
                'fp'
            );

            if (!in_array($fingerprint, $blockedFingerprints, true)) {
                $active[] = ['fp' => $fingerprint, 'at' => $now->format(DATE_ATOM), 'blocked' => true];
            }

            $blockedAttempts = ($existing?->getBlockedAttempts() ?? 0) + 1;
            $this->persist($existing, $customerId, $salesChannelId, $active, $context, [
                'blockedAttempts' => $blockedAttempts,
                'lastBlockedAt' => $now->format(DATE_ATOM),
            ]);

            $this->logger->warning('[card-velocity] blocked', [
                'distinctCards' => count($active),
                'customerId' => $customerId,
                'salesChannelId' => $salesChannelId,
                'blockedAttempts' => $blockedAttempts,
            ]);

            return false;
        }

        $active = array_values(array_filter(
            $active,
            static fn (array $entry): bool => $entry['fp'] !== $fingerprint
        ));
        $active[] = ['fp' => $fingerprint, 'at' => $now->format(DATE_ATOM)];
        $this->persist($existing, $customerId, $salesChannelId, $active, $context);

        return true;
    }

    private function maxDistinctCards(?string $salesChannelId): int
    {
        $configured = (int) ($this->configService->getConfig('cardVelocityMaxDistinctCards', $salesChannelId)
            ?: self::DEFAULT_MAX_DISTINCT_CARDS);

        return max(1, $configured);
    }

    private function windowHours(?string $salesChannelId): int
    {
        $configured = (int) ($this->configService->getConfig('cardVelocityWindowHours', $salesChannelId)
            ?: self::DEFAULT_WINDOW_HOURS);

        return max(1, $configured);
    }

    private function fetchRow(string $customerId, Context $context): ?CardVelocityEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customerId', $customerId))
            ->setLimit(1);
        $row = $this->cardVelocityRepository->search($criteria, $context)->first();

        return $row instanceof CardVelocityEntity ? $row : null;
    }

    private function persist(
        ?CardVelocityEntity $existing,
        string $customerId,
        ?string $salesChannelId,
        array $fingerprints,
        Context $context,
        array $extra = []
    ): void {
        $payload = array_merge([
            'id' => $existing?->getId() ?? Uuid::randomHex(),
            'customerId' => $customerId,
            'salesChannelId' => $salesChannelId,
            'fingerprints' => array_values($fingerprints),
            'distinctCards' => count($fingerprints),
        ], $extra);

        $this->cardVelocityRepository->upsert([$payload], $context);
    }

    private function pruneExpired(array $stored, \DateTimeImmutable $now, int $windowHours): array
    {
        $cutoff = $now->sub(new \DateInterval('PT' . $windowHours . 'H'));
        $active = [];

        foreach ($stored as $entry) {
            if (!is_array($entry) || !is_string($entry['fp'] ?? null) || !is_string($entry['at'] ?? null)) {
                continue;
            }

            try {
                $seenAt = new \DateTimeImmutable($entry['at']);
            } catch (\Exception) {
                continue;
            }

            if ($seenAt < $cutoff) {
                continue;
            }

            $kept = ['fp' => $entry['fp'], 'at' => $entry['at']];
            if (!empty($entry['blocked'])) {
                $kept['blocked'] = true;
            }
            $active[] = $kept;
        }

        return $active;
    }
}
