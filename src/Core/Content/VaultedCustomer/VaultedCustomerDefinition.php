<?php

declare(strict_types=1);

namespace NMIPayment\Core\Content\VaultedCustomer;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class VaultedCustomerDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'nmi_vaulted_customer';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return VaultedCustomerEntity::class;
    }

    public function getCollectionClass(): string
    {
        return VaultedCustomerCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new StringField('vaulted_customer_id', 'vaultedCustomerId'))->addFlags(new Required()),
            new LongTextField('billingId', 'billingId'),
            new StringField('default_billing', 'defaultBilling'),
            new OneToOneAssociationField('customer', 'customer_id', 'id', CustomerDefinition::class, false),
        ]);
    }
}
