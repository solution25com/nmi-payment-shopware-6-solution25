<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class AccountOrderPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class => 'onAccountOrderPageLoaded',
        ];
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $orders = $event->getPage()->getOrders();
        foreach ($orders as $order) {
            $customFields = $order->getCustomFields() ?? [];
            if (($customFields[NetThirtyFields::DEALER_PAYMENT_TYPE] ?? null) !== NetThirtyFields::DEALER_PAYMENT_TYPE_NET30) {
                continue;
            }

            $state = $order->getTransactions()?->first()?->getStateMachineState()?->getTechnicalName();

            if (
                $state !== OrderStateInstaller::STATE_TECHNICAL_NAME
                && $state !== OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
            ) {
                continue;
            }

            $dueDate = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;
            $alternateDueDate = $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] ?? null;

            if ($dueDate === null) {
                continue;
            }

            $dueDateObj = null;
            try {
                $dueDateObj = new \DateTimeImmutable($dueDate);
            } catch (\Exception $e) {
                $this->logger->warning('Invalid Net 30 due date', [
                    'order_id' => $order->getId(),
                    'value' => $dueDate,
                ]);
                continue;
            }

            $alternateDueDateObj = null;
            if ($alternateDueDate) {
                try {
                    $alternateDueDateObj = new \DateTimeImmutable($alternateDueDate);
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid Net 30 alternate due date', [
                        'order_id' => $order->getId(),
                        'value' => $alternateDueDate,
                    ]);
                }
            }

            $order->addExtension('dealerNet30', new ArrayStruct([
                'dueDate' => $dueDateObj,
                'alternateDueDate' => $alternateDueDateObj,
                'isOverdue' => $state === OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME,
            ]));
        }
    }
}
