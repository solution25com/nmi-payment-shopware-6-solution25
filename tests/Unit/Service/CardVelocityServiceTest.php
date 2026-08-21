<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Core\Content\CardVelocity\CardVelocityEntity;
use NMIPayment\Service\CardVelocityService;
use NMIPayment\Service\NMIConfigService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

#[CoversClass(CardVelocityService::class)]
class CardVelocityServiceTest extends TestCase
{
    private const CUSTOMER_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private EntityRepository&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private Context $context;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->context = Context::createDefaultContext();
    }

    public function testKnownCardCanBeRetriedWithoutAnotherWrite(): void
    {
        $this->givenStoredRow($this->row(['1111|1029', '4444|1130', '0005|1227']));
        $this->repository->expects(self::never())->method('upsert');

        self::assertTrue($this->service()->registerAttempt(
            self::CUSTOMER_ID,
            null,
            '4444|1130',
            $this->context
        ));
    }

    public function testFourthDistinctCardIsBlockedAndRecorded(): void
    {
        $this->givenStoredRow($this->row(['1111|1029', '4444|1130', '0005|1227']));
        $payload = null;
        $this->repository
            ->expects(self::once())
            ->method('upsert')
            ->willReturnCallback(function (array $rows) use (&$payload): EntityWrittenContainerEvent {
                $payload = $rows[0];

                return $this->createMock(EntityWrittenContainerEvent::class);
            });

        self::assertFalse($this->service()->registerAttempt(
            self::CUSTOMER_ID,
            null,
            '1117|0131',
            $this->context
        ));
        self::assertSame(1, $payload['blockedAttempts']);
        self::assertTrue($payload['fingerprints'][3]['blocked']);
    }

    private function service(): CardVelocityService
    {
        return new CardVelocityService(
            $this->repository,
            new NMIConfigService(new StaticSystemConfigService()),
            $this->logger
        );
    }

    /** @param list<string> $fingerprints */
    private function row(array $fingerprints): CardVelocityEntity
    {
        $row = new CardVelocityEntity();
        $row->setId('0123456789abcdef0123456789abcdef');
        $row->setCustomerId(self::CUSTOMER_ID);
        $row->setFingerprints(array_map(
            static fn (string $fingerprint): array => [
                'fp' => $fingerprint,
                'at' => (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM),
            ],
            $fingerprints
        ));

        return $row;
    }

    private function givenStoredRow(?CardVelocityEntity $row): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($row);
        $this->repository->method('search')->willReturn($result);
    }
}
