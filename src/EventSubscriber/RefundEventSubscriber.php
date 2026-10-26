<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Service\NmiRefundService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RefundEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?EntityRepository $orderReturnRepository,
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly NmiRefundService $refundService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateMachineTransition',
        ];
    }

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        $isReturnInProgress = $event->getEntityName() === 'order_return'
            && $event->getToPlace()->getTechnicalName() === 'in_progress';
        if (!$isReturnInProgress || $this->orderReturnRepository === null) {
            return;
        }

        $context = $event->getContext();
        $returnId = $event->getEntityId();

        try {
            $criteria = new Criteria([$returnId]);
            $criteria->addAssociation('order');
            $orderReturn = $this->orderReturnRepository->search($criteria, $context)->getEntities()->first();

            if (!$orderReturn instanceof Entity || $orderReturn->get('order') === null) {
                $this->logger->warning('Order return not found for refund', ['returnId' => $returnId]);

                return;
            }

            $order = $orderReturn->get('order');
            $orderTransaction = $this->latestOrderTransaction($order->getId(), $context);
            if ($orderTransaction === null) {
                $this->logger->warning('No order transaction found for refund', ['orderId' => $order->getId()]);

                return;
            }

            $outcome = $this->refundService->refund(
                $returnId,
                $order->getId(),
                $order->getSalesChannelId(),
                (float) $orderReturn->get('amountTotal'),
                $this->nmiPaymentType($orderTransaction),
                $context
            );

            if ($outcome['result'] !== NmiRefundService::RESULT_REFUNDED) {
                return;
            }

            try {
                $outcome['fullyRefunded']
                    ? $this->transactionStateHandler->refund($orderTransaction->getId(), $context)
                    : $this->transactionStateHandler->refundPartially($orderTransaction->getId(), $context);
            } catch (\Throwable $e) {
                $this->logger->warning('Refunded at NMI but the order transaction state was not updated', [
                    'orderId' => $order->getId(),
                    'returnId' => $returnId,
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Refund processing failed', [
                'returnId' => $returnId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    private function latestOrderTransaction(string $orderId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('paymentMethod');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        return $orderTransaction instanceof OrderTransactionEntity ? $orderTransaction : null;
    }

    private function nmiPaymentType(OrderTransactionEntity $orderTransaction): string
    {
        return $orderTransaction->getPaymentMethod()?->getHandlerIdentifier() === AchEcheck::class ? 'check' : 'creditcard';
    }
}
