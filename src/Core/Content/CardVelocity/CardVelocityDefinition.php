<?php declare(strict_types=1);

namespace NMIPayment\Core\Content\CardVelocity;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

class CardVelocityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'nmi_card_velocity';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return CardVelocityEntity::class;
    }

    public function getCollectionClass(): string
    {
        return CardVelocityCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            new FkField('sales_channel_id', 'salesChannelId', SalesChannelDefinition::class),
            (new JsonField('fingerprints', 'fingerprints'))->removeFlag(ApiAware::class),
            new IntField('distinct_cards', 'distinctCards'),
            new IntField('blocked_attempts', 'blockedAttempts'),
            new DateTimeField('last_blocked_at', 'lastBlockedAt'),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id'),
            new ManyToOneAssociationField('salesChannel', 'sales_channel_id', SalesChannelDefinition::class, 'id'),
        ]);
    }
}
