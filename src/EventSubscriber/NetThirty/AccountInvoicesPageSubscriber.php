<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\DealerDocumentCriteriaService;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Shopware\Core\Checkout\Order\Event\OrderCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Event\RouteRequest\OrderRouteRequestEvent;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class AccountInvoicesPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DealerDocumentCriteriaService $documentCriteriaService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderRouteRequestEvent::class => 'onOrderRouteRequest',
            OrderCriteriaEvent::class => 'onOrderCriteria',
            AccountOrderPageLoadedEvent::class => 'onAccountOrderPageLoaded',
        ];
    }

    public function onOrderCriteria(OrderCriteriaEvent $event): void
    {
        $this->documentCriteriaService->adjustDocumentCriteria(
            $event->getCriteria(),
            $event->getSalesChannelContext()
        );
    }

    public function onOrderRouteRequest(OrderRouteRequestEvent $event): void
    {
        $route = $event->getStorefrontRequest()->attributes->get('_route');
        if ($route !== 'frontend.account.invoices.page') {
            return;
        }

        $criteria = $event->getCriteria();
        $filter = $event->getStorefrontRequest()->query->get('filter', 'all');

        $criteria->addFilter(
            new EqualsFilter('customFields.' . NetThirtyFields::DEALER_PAYMENT_TYPE, NetThirtyFields::DEALER_PAYMENT_TYPE_NET30)
        );

        if ($filter === 'unpaid') {
            $criteria->addFilter(
                new OrFilter([
                    new EqualsFilter('transactions.stateMachineState.technicalName', OrderStateInstaller::STATE_TECHNICAL_NAME),
                    new EqualsFilter('transactions.stateMachineState.technicalName', OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME),
                ])
            );
        }
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if ($route !== 'frontend.account.invoices.page') {
            return;
        }

        $orders = $event->getPage()->getOrders();

        foreach ($orders as $order) {
            $customFields = $order->getCustomFields() ?? [];
            $state = $order->getTransactions()?->first()?->getStateMachineState()?->getTechnicalName();

            $dueDate = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;
            $alternateDueDate = $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] ?? null;

            $dueDateObj = null;
            if ($dueDate) {
                try {
                    $dueDateObj = new \DateTimeImmutable($dueDate);
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid Net 30 due date in invoices page', [
                        'order_id' => $order->getId(),
                        'value' => $dueDate,
                    ]);
                }
            }

            $alternateDueDateObj = null;
            if ($alternateDueDate) {
                try {
                    $alternateDueDateObj = new \DateTimeImmutable($alternateDueDate);
                } catch (\Exception $e) {
                    $this->logger->warning('Invalid Net 30 alternate due date in invoices page', [
                        'order_id' => $order->getId(),
                        'value' => $alternateDueDate,
                    ]);
                }
            }

            $isPaid = $state === 'paid';
            $isOverdue = $state === OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME;
            $isPending = $state === OrderStateInstaller::STATE_TECHNICAL_NAME;

            $order->addExtension('dealerNet30', new ArrayStruct([
                'dueDate' => $dueDateObj,
                'alternateDueDate' => $alternateDueDateObj,
                'isOverdue' => $isOverdue,
                'isPaid' => $isPaid,
                'isPending' => $isPending,
                'paymentStatus' => $state,
            ]));
        }
    }
}
