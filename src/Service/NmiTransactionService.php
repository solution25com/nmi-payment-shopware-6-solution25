<?php

namespace NMIPayment\Service;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class NmiTransactionService
{
    /** @var EntityRepository<\NMIPayment\Core\Content\Transaction\NmiTransactionCollection> */
    private EntityRepository $nmiTransactionRepository;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
    private EntityRepository $orderRepository;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
    private EntityRepository $orderTransactionRepository;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $nmiTransactionRepository,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->nmiTransactionRepository = $nmiTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    public function updateTransactionStatus($orderId, $status, $context): void
    {
        /** @var NmiTransactionEntity|null $transaction */

        $transaction = $this->getTransactionByOrderId($orderId, $context);
        if ($transaction === null) {
            $this->logger->warning('No NMI transaction found to update the status of', ['orderId' => $orderId, 'status' => $status]);
            return;
        }

        $this->nmiTransactionRepository->update([
        [
        'id' => $transaction->getId(),
        'status' => $status,
        'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s')
        ]
        ], $context);

        $this->orderRepository->upsert([[
        'id' => $orderId,
        'nmiTransaction' => [
        'data' => [
          'id' => $transaction->getId(),
          'nmiTransactionId' => $transaction->getId(),
          'paymentMethodName' => $transaction->getPaymentMethodName(),
          'subscriptionTransactionId' => $transaction->getSubscriptionTransactionId(),
          'isSubscription' => $transaction->getIsSubscription(),
          'status' => $status,
        ]
        ]
        ]], $context);
    }

    public function addTransaction(
        $orderId,
        $paymentMethodName,
        $transactionId,
        $subscriptionTransactionId,
        $isSubscription,
        $status,
        $selectedBillingId,
        $context,
        ?float $authAmount = null
    ): void {
        if (trim((string) $transactionId) === '') {
            throw new \InvalidArgumentException('NMI transaction ID must not be empty.');
        }

        $tableNmiId = Uuid::randomHex();
        $this->nmiTransactionRepository->upsert([
        [
        'id' => $tableNmiId,
        'orderId' => $orderId,
        'paymentMethodName' => $paymentMethodName,
        'transactionId' => $transactionId,
        'subscriptionTransactionId' => $subscriptionTransactionId,
        'isSubscription' => $isSubscription,
        'status' => $status,
        'selectedBillingId' => $selectedBillingId,
        'authAmount' => $authAmount,
        'createdAt' => (new \DateTime())->format('Y-m-d H:i:s')
        ]
        ], $context);

        $this->orderRepository->upsert([[
        'id' => $orderId,
        'nmiTransaction' => [
        'data' => [
          'id' => $tableNmiId,
          'nmiTransactionId' => $transactionId,
          'subscriptionTransactionId' => $subscriptionTransactionId,
          'isSubscription' => $isSubscription,
          'paymentMethodName' => $paymentMethodName,
          'status' => $status,
        ]
        ]
        ]], $context);
    }

    /**
     * The order's newest NMI transaction. An order can hold several rows (a payment retried from
     * the account order page adds one), and an unsorted result made capture, void and refund act
     * on whichever row the database returned last, possibly a failed earlier attempt.
     */
    public function getTransactionByOrderId(string $orderId, Context $context): ?NmiTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING),
            new FieldSorting('id', FieldSorting::DESCENDING)
        );
        $criteria->setLimit(1);
        try {
            return $this->nmiTransactionRepository->search($criteria, $context)->getEntities()->first();
        } catch (\Exception $e) {
            $this->logger->error('NMI transaction lookup by order failed', [
                'orderId' => $orderId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function updateAuthAmount(string $nmiTransactionRowId, float $authAmount, Context $context): void
    {
        $this->nmiTransactionRepository->update([[
            'id' => $nmiTransactionRowId,
            'authAmount' => $authAmount,
            'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]], $context);
    }

    public function getOrderByTransactionId(string $transactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->setIds([$transactionId]);
        try {
            return $this->orderTransactionRepository->search($criteria, $context)->getEntities()->last();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getTransactionByTransactionId(string $transactionId, Context $context): ?NmiTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactionId', $transactionId));
        try {
            return $this->nmiTransactionRepository->search($criteria, $context)->getEntities()->last();
        } catch (\Exception $e) {
            return null;
        }
    }
}
