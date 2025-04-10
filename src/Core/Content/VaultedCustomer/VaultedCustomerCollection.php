<?php

declare(strict_types=1);

namespace NMIPayment\Core\Content\VaultedCustomer;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                       add(VaultedCustomerEntity $entity)
 * @method void                       set(string $key, VaultedCustomerEntity $entity)
 * @method VaultedCustomerEntity[]    getIterator()
 * @method VaultedCustomerEntity[]    getElements()
 * @method null|VaultedCustomerEntity get(string $key)
 * @method null|VaultedCustomerEntity first()
 * @method null|VaultedCustomerEntity last()
 */
class VaultedCustomerCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return VaultedCustomerEntity::class;
    }
}
