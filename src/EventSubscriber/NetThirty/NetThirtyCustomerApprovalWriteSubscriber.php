<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use NMIPayment\Service\NetThirtyFields;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Exception\MissingPrivilegeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NetThirtyCustomerApprovalWriteSubscriber implements EventSubscriberInterface
{
    public const PRIVILEGE_UPDATE = 'nmi_net30_approval:update';

    public function __construct(
        private readonly EntityRepository $customerRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'validateNetThirtyApprovalWrite',
        ];
    }

    public function validateNetThirtyApprovalWrite(PreWriteValidationEvent $event): void
    {
        $context = $event->getContext();
        if ($context->isAllowed(self::PRIVILEGE_UPDATE)) {
            return;
        }

        foreach ($event->getCommands() as $command) {
            if (!$this->changesNetThirtyApproval($command, $context)) {
                continue;
            }

            $event->getExceptions()->add(new MissingPrivilegeException([self::PRIVILEGE_UPDATE]));

            return;
        }
    }

    private function changesNetThirtyApproval(WriteCommand $command, Context $context): bool
    {
        if ($command->getEntityName() !== 'customer') {
            return false;
        }

        $payload = $command->getPayload();
        $customFields = $payload['custom_fields'] ?? $payload['customFields'] ?? null;
        if (!is_array($customFields) || !array_key_exists(NetThirtyFields::CUSTOMER_APPROVED, $customFields)) {
            return false;
        }

        $newValue = $customFields[NetThirtyFields::CUSTOMER_APPROVED] === true;
        $customerId = $command->getDecodedPrimaryKey()['id'] ?? null;
        if ($customerId === null) {
            return $newValue;
        }

        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context)->first();
        if (!$customer instanceof CustomerEntity) {
            return $newValue;
        }

        $existingFields = $customer->getCustomFields() ?? [];
        $existingValue = ($existingFields[NetThirtyFields::CUSTOMER_APPROVED] ?? false) === true;

        return $newValue !== $existingValue;
    }
}
