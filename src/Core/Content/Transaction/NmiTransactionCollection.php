<?php

declare(strict_types=1);

namespace NMIPayment\Core\Content\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(NmiTransactionEntity $entity)
 * @method void set(string $key, NmiTransactionEntity $entity)
 * @method NmiTransactionEntity[] getIterator()
 * @method NmiTransactionEntity[] getElements()
 * @method NmiTransactionEntity|null get(string $key)
 * @method NmiTransactionEntity|null first()
 * @method NmiTransactionEntity|null last()
 */
class NmiTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return NmiTransactionEntity::class;
    }
}
