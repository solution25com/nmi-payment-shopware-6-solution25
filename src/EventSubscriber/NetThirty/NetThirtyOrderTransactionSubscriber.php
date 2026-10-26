<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Gateways\Net30;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyOrderLifecycleService;
use NMIPayment\Service\NetThirtyPaymentOrchestrator;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NetThirtyOrderTransactionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
        private readonly EntityRepository $orderTransactionRepository,
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
        private readonly EntityRepository $orderRepository,
        private readonly NetThirtyPaymentLinkService $paymentLinkService,
        private readonly NetThirtyOrderLifecycleService $orderLifecycleService,
        private readonly NetThirtyPaymentOrchestrator $paymentOrchestrator,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'order_transaction.written' => 'onOrderTransactionWritten',
        ];
    }

    public function onOrderTransactionWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        if (!$context->getSource() instanceof AdminApiSource) {
            return;
        }

        static $qaDepth = 0;
        $qaDepth++;
        $this->logger->info('[QA-DEBUG] enter', ['depth' => $qaDepth]);
        foreach ($event->getWriteResults() as $wr) {
            $this->logger->info('[QA-DEBUG] write', ['depth' => $qaDepth, 'pk' => $wr->getPrimaryKey(), 'op' => $wr->getOperation(), 'exists' => $wr->getExistence()?->exists(), 'payload_keys' => array_keys($wr->getPayload())]);
        }
        try {
            $isNewById = [];
            foreach ($event->getWriteResults() as $writeResult) {
                $existence = $writeResult->getExistence();
                $isNewById[(string) $writeResult->getPrimaryKey()] = !$existence || !$existence->exists();
            }

            if ($isNewById === []) {
                return;
            }

            $transactions = $this->getOrderTransactions(array_map('strval', array_keys($isNewById)), $context);

            foreach ($isNewById as $transactionId => $isNew) {
                $transactionId = (string) $transactionId;
                $transaction = $transactions->get($transactionId);
                if (!$transaction) {
                    continue;
                }

                $paymentMethod = $transaction->getPaymentMethod();
                if (!$paymentMethod) {
                    continue;
                }

                $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
                $isNet30 = $handlerIdentifier === Net30::class
                || $handlerIdentifier === 'NMIPayment\\Payment\\NetThirtyPaymentHandler';

                if (!$isNet30) {
                    continue;
                }

                $order = $transaction->getOrder();
                if (!$order) {
                    continue;
                }

                $currentState = $transaction->getStateMachineState()?->getTechnicalName();
                $customFields = $order->getCustomFields() ?? [];
                $hasPaymentLink = isset($customFields[NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()]);
                $hasEmailSentFlag = isset($customFields[NetThirtyFields::PAYMENT_LINK_EMAIL_SENT]);
                $paymentCompleted = isset($customFields[NetThirtyFields::PAYMENT_COMPLETED]) && $customFields[NetThirtyFields::PAYMENT_COMPLETED] === true;

                if ($currentState === 'paid' || $paymentCompleted) {
                    continue;
                }

                if ($currentState === OrderStateInstaller::STATE_TECHNICAL_NAME && $hasPaymentLink && $hasEmailSentFlag) {
                    continue;
                }

                if (!$isNew) {
                    continue;
                }

                try {
                    $this->transitionToNetThirty($transactionId, $order->getId(), $currentState, $context);

                    $order = $this->reloadOrderWithCustomer($order->getId(), $context);

                    if (!$order) {
                        continue;
                    }

                    if ($this->orderLifecycleService->hasPaymentLinkEmailSent($order)) {
                        continue;
                    }

                    $expirationDate = (new \DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
                    $paymentLinkData = $this->paymentLinkService->createPaymentLink($order, $context, 30);
                    $paymentLinkUrl = $paymentLinkData['payment_link_url'];

                    [$customFields, $paymentLinkUrl] = $this->orderLifecycleService->buildOrderCustomFields(
                        $order,
                        $paymentLinkData,
                        $expirationDate,
                        true
                    );

                    $this->orderLifecycleService->updateOrderCustomFields($order->getId(), $customFields, $context);

                    if (!$this->orderLifecycleService->hasPaymentLinkEmailSent($order)) {
                        $this->paymentOrchestrator->sendPaymentLinkEmailIfNeeded(
                            $order,
                            $paymentLinkUrl,
                            $expirationDate,
                            $context
                        );
                    }

                    $this->logger->info('[NET30] Successfully processed Net 30 admin order', [
                    'transaction_id' => $transactionId,
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('[NET30] Failed to process Net 30 admin order', [
                    'transaction_id' => $transactionId,
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber() ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        } finally {
            $qaDepth--;
        }
    }

    /**
     * @param list<string> $transactionIds
     */
    private function getOrderTransactions(array $transactionIds, Context $context): OrderTransactionCollection
    {
        $criteria = new Criteria($transactionIds);
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('order');
        $criteria->addAssociation('stateMachineState');

        return $this->orderTransactionRepository->search($criteria, $context)->getEntities();
    }

    /**
     * The order is read again after the state transition, so the payment link is built from its
     * current state.
     */
    private function reloadOrderWithCustomer(string $orderId, Context $context): ?OrderEntity
    {
        return $this->orderRepository->search(
            (new Criteria([$orderId]))->addAssociation('orderCustomer'),
            $context
        )->getEntities()->first();
    }

    private function transitionToNetThirty(
        string $transactionId,
        string $orderId,
        ?string $currentState,
        Context $context
    ): void {
        if ($currentState === OrderStateInstaller::STATE_TECHNICAL_NAME) {
            return;
        }

        $this->paymentOrchestrator->transitionToNetThirtyIfNeeded(
            $transactionId,
            $currentState,
            $context
        );
    }
}
