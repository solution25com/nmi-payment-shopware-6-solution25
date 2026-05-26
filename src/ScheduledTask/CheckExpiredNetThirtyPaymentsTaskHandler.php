<?php

declare(strict_types=1);

namespace NMIPayment\ScheduledTask;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class CheckExpiredNetThirtyPaymentsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public static function getHandledMessages(): iterable
    {
        return [CheckExpiredNetThirtyPaymentsTask::class];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $now = new \DateTime();
        $fromDate = (clone $now)->modify('-40 days');

        $this->logger->info('Checking for expired Net 30 payment links', [
            'current_time' => $now->format('Y-m-d H:i:s'),
        ]);

        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('customFields', NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()));
        $criteria->addFilter(new RangeFilter('orderDateTime', [
            'gte' => $fromDate->format('Y-m-d H:i:s'),
        ]));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->setLimit(100);

        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        $this->logger->info('Found Net 30 orders to check', [
            'total_orders' => $orders->count(),
        ]);

        $expiredCount = 0;
        $overdueCount = 0;

        foreach ($orders as $order) {
            $customFields = $order->getCustomFields() ?? [];

            if (isset($customFields[NetThirtyFields::PAYMENT_COMPLETED]) && $customFields[NetThirtyFields::PAYMENT_COMPLETED] === true) {
                continue;
            }

            $alternateDueDateStr = $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] ?? null;
            $expirationDateStr = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;

            $dueDateStr = $alternateDueDateStr ?? $expirationDateStr;

            if ($dueDateStr === null) {
                continue;
            }

            try {
                $dueDate = new \DateTime($dueDateStr);
                if (strlen($dueDateStr) === 10) {
                    $dueDate->setTime(23, 59, 59);
                }

                if ($dueDate < $now) {
                    $expiredCount++;

                    $orderTransaction = $order->getTransactions()?->first();
                    if ($orderTransaction === null) {
                        continue;
                    }

                    $currentState = $orderTransaction->getStateMachineState()?->getTechnicalName();

                    if ($currentState === OrderStateInstaller::STATE_TECHNICAL_NAME) {
                        try {
                            $this->stateMachineRegistry->transition(
                                new Transition(
                                    'order_transaction',
                                    $orderTransaction->getId(),
                                    OrderStateInstaller::OVERDUE_ACTION_NAME,
                                    'stateId'
                                ),
                                $context
                            );

                            $customFields[NetThirtyFields::PAYMENT_OVERDUE] = true;
                            $customFields[NetThirtyFields::PAYMENT_OVERDUE_AT] = $now->format('Y-m-d H:i:s');

                            $this->orderRepository->update([[
                                'id' => $order->getId(),
                                'customFields' => $customFields,
                            ]], $context);

                            $overdueCount++;

                            $this->logger->info('Marked expired Net 30 payment as overdue for order: ' . $order->getOrderNumber(), [
                                'order_id' => $order->getId(),
                                'order_number' => $order->getOrderNumber(),
                                'expiration_date' => $expirationDateStr,
                                'alternate_due_date' => $alternateDueDateStr,
                            ]);
                        } catch (IllegalTransitionException $e) {
                            $this->logger->warning('Cannot transition expired Net 30 order to overdue state: ' . $order->getOrderNumber(), [
                                'order_id' => $order->getId(),
                                'current_state' => $currentState,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Error processing expired Net 30 payment for order: ' . $order->getOrderNumber(), [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Completed expired Net 30 payment check', [
            'total_orders_checked' => $orders->count(),
            'expired_orders_found' => $expiredCount,
            'orders_marked_overdue' => $overdueCount,
        ]);
    }
}
