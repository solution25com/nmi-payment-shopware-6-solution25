<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\EventSubscriber;

use NMIPayment\EventSubscriber\NetThirty\NetThirtyOrderTransactionSubscriber;
use NMIPayment\Service\NetThirtyOrderLifecycleService;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use NMIPayment\Service\NetThirtyPaymentOrchestrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

#[CoversClass(NetThirtyOrderTransactionSubscriber::class)]
class NetThirtyOrderTransactionSubscriberTest extends TestCase
{
    private const TRANSACTION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SNAPSHOT_VERSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private EntityRepository&MockObject $transactionRepository;
    private NetThirtyPaymentOrchestrator&MockObject $orchestrator;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->orchestrator = $this->createMock(NetThirtyPaymentOrchestrator::class);
    }

    public function testTransactionCopiesOfAnOrderSnapshotAreIgnored(): void
    {
        // Generating the NET 30 invoice snapshots the order: every transaction is written again
        // as an insert into the snapshot version. Handling those sent one email per copy.
        $this->transactionRepository->expects(self::never())->method('search');
        $this->orchestrator->expects(self::never())->method('sendPaymentLinkEmailIfNeeded');

        $this->subscriber()->onOrderTransactionWritten($this->event(
            ['versionId' => self::SNAPSHOT_VERSION_ID],
            new Context(new AdminApiSource(null))
        ));
    }

    public function testEventsInAVersionContextAreIgnored(): void
    {
        $this->transactionRepository->expects(self::never())->method('search');

        $this->subscriber()->onOrderTransactionWritten($this->event(
            ['versionId' => self::SNAPSHOT_VERSION_ID],
            new Context(new AdminApiSource(null), versionId: self::SNAPSHOT_VERSION_ID)
        ));
    }

    public function testNewLiveTransactionsAreLoaded(): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new OrderTransactionCollection());

        $this->transactionRepository->expects(self::once())
            ->method('search')
            ->with(self::callback(static fn (Criteria $criteria): bool => $criteria->getIds() === [self::TRANSACTION_ID]))
            ->willReturn($result);

        $this->subscriber()->onOrderTransactionWritten($this->event(
            ['versionId' => Defaults::LIVE_VERSION],
            new Context(new AdminApiSource(null))
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload, Context $context): EntityWrittenEvent
    {
        return new EntityWrittenEvent(
            'order_transaction',
            [new EntityWriteResult(self::TRANSACTION_ID, $payload + ['id' => self::TRANSACTION_ID], 'order_transaction', EntityWriteResult::OPERATION_INSERT)],
            $context
        );
    }

    private function subscriber(): NetThirtyOrderTransactionSubscriber
    {
        return new NetThirtyOrderTransactionSubscriber(
            $this->transactionRepository,
            $this->createMock(EntityRepository::class),
            $this->createMock(NetThirtyPaymentLinkService::class),
            $this->createMock(NetThirtyOrderLifecycleService::class),
            $this->orchestrator,
            $this->createMock(LoggerInterface::class)
        );
    }
}
