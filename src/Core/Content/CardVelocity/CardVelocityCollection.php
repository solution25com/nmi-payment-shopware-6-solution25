<?php declare(strict_types=1);

namespace NMIPayment\Core\Content\CardVelocity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<CardVelocityEntity>
 */
class CardVelocityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return CardVelocityEntity::class;
    }
}
