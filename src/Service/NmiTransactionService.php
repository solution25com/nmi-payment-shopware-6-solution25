<?php

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class NmiTransactionService
{
    public function __construct(private readonly EntityRepository $nmiTransactionRepository, private readonly EntityRepository $orderRepository, private readonly EntityRepository $orderTransactionRepository) {}

    public function updateTransactionStatus(string $orderId, $status, Context $context): void
    {
        $transaction = $this->getTransactionByOrderId($orderId, $context);

        $this->nmiTransactionRepository->update([
            [
                'id' => $transaction->getId(),
                'status' => $status,
                'updatedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
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
                ],
            ],
        ]], $context);
    }

    public function addTransaction($orderId, $paymentMethodName, $transactionId, $subscriptionTransactionId, $isSubscription, $status, Context $context): void
    {
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
                'createdAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
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
                ],
            ],
        ]], $context);
    }

    public function getTransactionByOrderId(string $orderId, Context $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        try {
            return $this->nmiTransactionRepository->search($criteria, $context)->last();
        } catch (\Exception) {
            return null;
        }
    }

    public function getOrderByTransactionId(string $transactionId, Context $context): ?Entity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $transactionId));

        try {
            return $this->orderTransactionRepository->search($criteria, $context)->last();
        } catch (\Exception) {
            return null;
        }
    }
}
