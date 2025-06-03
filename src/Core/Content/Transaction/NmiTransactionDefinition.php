<?php declare(strict_types=1);

namespace NMIPayment\Core\Content\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

class NmiTransactionDefinition extends EntityDefinition
{
  public const ENTITY_NAME = 'nmi_transaction';

  public function getEntityName(): string
  {
    return self::ENTITY_NAME;
  }

  public function getEntityClass(): string
  {
    return NmiTransactionEntity::class;
  }

  public function getCollectionClass(): string
  {
    return NmiTransactionCollection::class;
  }

  protected function defineFields(): FieldCollection
  {
    return new FieldCollection([
      (new IdField('id', 'id'))->addFlags(new ApiAware(), new Required(), new PrimaryKey()),
      (new StringField('order_id', 'orderId'))->addFlags(new ApiAware()),
      (new StringField('payment_method_name', 'paymentMethodName'))->addFlags(new ApiAware()),
      (new StringField('transaction_id', 'transactionId'))->addFlags(new ApiAware()),
      (new StringField('subscription_transaction_id', 'subscriptionTransactionId'))->addFlags(new ApiAware()),
      (new BoolField('isSubscription', 'isSubscription'))->addFlags(new ApiAware()),
      (new StringField('selectedBillingId', 'selectedBillingId'))->addFlags(new ApiAware()),
        (new StringField('status', 'status'))->addFlags(new ApiAware(), new Required()),
    ]);
  }
}