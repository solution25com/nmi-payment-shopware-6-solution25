<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use NMIPayment\Service\NMIRecurringVaultedCustomer;
use S25Subscription\Checkout\Cart\Event\GenerateRecurringEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RecurringSubscriber implements EventSubscriberInterface
{
    private NMIRecurringVaultedCustomer $recurringVaultedCustomer;

    public function __construct(NMIRecurringVaultedCustomer $recurringVaultedCustomer)
    {
        $this->recurringVaultedCustomer = $recurringVaultedCustomer;
    }



    public static function getSubscribedEvents(): array
    {
        return [
          GenerateRecurringEvent::class => 'onRecurringGenerated',
        ];
    }

    public function onRecurringGenerated(GenerateRecurringEvent $event): void
    {
        $this->recurringVaultedCustomer->recurringCapture($event->getAmount(), $event->getCustomerId(), $event->getOrderId(), $event->getContext());
    }
}