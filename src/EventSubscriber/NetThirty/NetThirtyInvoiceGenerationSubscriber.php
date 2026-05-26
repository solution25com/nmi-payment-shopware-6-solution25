<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\DealerConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class NetThirtyInvoiceGenerationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DocumentGenerator $documentGenerator,
        private readonly DealerConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChanged',
        ];
    }

    public function onOrderTransactionStateChanged(StateMachineStateChangeEvent $event): void
    {
        if (!$this->configService->isAutoInvoiceGenerationEnabled()) {
            return;
        }

        $toStateName = $event->getNextState()->getTechnicalName();

        if (
            $toStateName !== OrderStateInstaller::STATE_TECHNICAL_NAME
            && $toStateName !== OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
        ) {
            return;
        }

        $transactionId = $event->getTransition()->getEntityId();
        $context = $event->getContext();

        $order = $this->findOrderByTransactionId($transactionId, $context);
        if ($order === null) {
            $this->logger->warning('[NET30-Invoice] Could not find order for transaction', [
                'transaction_id' => $transactionId,
            ]);
            return;
        }

        if ($this->orderAlreadyHasInvoice($order)) {
            return;
        }

        $this->generateInvoice($order, $context);
    }

    private function findOrderByTransactionId(string $transactionId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $criteria->addAssociation('documents.documentType');
        $criteria->setLimit(1);

        return $this->orderRepository->search($criteria, $context)->first();
    }

    private function orderAlreadyHasInvoice(OrderEntity $order): bool
    {
        $documents = $order->getDocuments();
        if ($documents === null || $documents->count() === 0) {
            return false;
        }

        foreach ($documents as $document) {
            if ($document->getDocumentType()?->getTechnicalName() === 'invoice') {
                return true;
            }
        }

        return false;
    }

    private function generateInvoice(OrderEntity $order, Context $context): void
    {
        try {
            $operation = new DocumentGenerateOperation(
                $order->getId(),
                'pdf',
                ['displayInCustomerAccount' => true]
            );

            $result = $this->documentGenerator->generate(
                'invoice',
                [$order->getId() => $operation],
                $context
            );

            if (count($result->getSuccess()) > 0) {
                $this->logger->info('[NET30-Invoice] Invoice generated successfully', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                ]);
            }

            foreach ($result->getErrors() as $error) {
                $this->logger->error('[NET30-Invoice] Failed to generate invoice', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[NET30-Invoice] Exception generating invoice', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
