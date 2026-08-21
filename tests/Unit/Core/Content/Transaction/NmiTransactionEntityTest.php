<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Core\Content\Transaction;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NmiTransactionEntity::class)]
class NmiTransactionEntityTest extends TestCase
{
    public function testLegacyNullableOrderIdFallsBackToEmptyString(): void
    {
        $entity = new NmiTransactionEntity();
        $entity->setOrderId(null);

        self::assertSame('', $entity->getOrderId());
        self::assertSame('', $entity->getTransactionId());
        self::assertSame('', $entity->getSelectedBillingId());
    }
}
