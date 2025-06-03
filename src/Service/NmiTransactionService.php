<?php

namespace NMIPayment\Service;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class NmiTransactionService
{
  private EntityRepository $nmiTransactionRepository;
  private EntityRepository $orderRepository;
  private EntityRepository $orderTransactionRepository;
  private LoggerInterface $logger;

  public function __construct(EntityRepository $nmiTransactionRepository,
                              EntityRepository $orderRepository,
                              EntityRepository $orderTransactionRepository,
                              LoggerInterface $logger)
  {
    $this->nmiTransactionRepository = $nmiTransactionRepository;
    $this->orderRepository = $orderRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->logger = $logger;
  }

  public function updateTransactionStatus($orderId, $status, $context): void
  {
    $transaction = $this->getTransactionByOrderId($orderId, $context);

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

  public function addTransaction($orderId, $paymentMethodName, $transactionId, $subscriptionTransactionId, $isSubscription, $status, $selectedBillingId, $context): void
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
        'selectedBillingId' => $selectedBillingId,
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

  public function getTransactionByOrderId(string $orderId, Context $context): null|Entity
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    try {
      return $this->nmiTransactionRepository->search($criteria, $context)->last();
    } catch (\Exception $e) {
      return null;
    }
  }

  public function getOrderByTransactionId(string $transactionId, Context $context): null|Entity
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('id', $transactionId));
    try {
      return $this->orderTransactionRepository->search($criteria, $context)->last();
    } catch (\Exception $e) {
      return null;
    }
  }

}