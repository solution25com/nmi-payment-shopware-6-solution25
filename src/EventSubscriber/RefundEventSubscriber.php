<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RefundEventSubscriber implements EventSubscriberInterface
{

  private NmiTransactionService $nmiTransactionService;
  private NMIPaymentApiClient $nmiPaymentApiClient;
  private EntityRepository $orderReturnRepository;
  private EntityRepository $orderTransactionRepository;
  private OrderTransactionStateHandler $transactionStateHandler;
  private NmiConfigService $nmiConfigService;
  private LoggerInterface $logger;

  public function __construct(
    NmiTransactionService $nmiTransactionService,
    NMIPaymentApiClient          $NMIPaymentApiClient,
    EntityRepository           $orderReturnRepository,
    EntityRepository           $orderTransactionRepository,
    OrderTransactionStateHandler $transactionStateHandler,
    NMIConfigService           $configService,
    LoggerInterface            $logger)
  {
    $this->nmiTransactionService = $nmiTransactionService;
    $this->nmiPaymentApiClient = $NMIPaymentApiClient;
    $this->orderReturnRepository = $orderReturnRepository;
    $this->orderTransactionRepository = $orderTransactionRepository;
    $this->transactionStateHandler = $transactionStateHandler;
    $this->nmiConfigService = $configService;
    $this->logger = $logger;
  }

  public static function getSubscribedEvents()
  {
    return [
      'state_enter.order_return.state.in_progress' => 'onProgressRefund'
    ];
  }

  public function onProgressRefund(OrderStateMachineStateChangeEvent $event): void
  {
  try{

    $context = $event->getContext();
    $order = $event->getOrder();
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $order->getId()));
    $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
    $transaction = $this->nmiTransactionService->getTransactionByOrderId($order->getId(), $context);
    $orderTransactionId = $this->getOrderTransactionIdByOrderId($order->getId(), $context);
    $orderTotalAmount = $order->getAmountTotal();


    if ($transaction && strtolower($transaction->getStatus()) == strtolower(TransactionStatuses::PAID->value)) {
      $postData = [
        'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
        'type' => 'refund',
        'transactionid' => $transaction->getTransactionId(),
        'payment' => 'creditcard',
        'amount' => $orderReturn->getAmountTotal(),
      ];

      $response = $this->nmiPaymentApiClient->createTransaction($postData);
      if($response['responsetext'] == 'SUCCESS') {
        if($orderReturn->getAmountTotal() == $orderTotalAmount) {
          $this->transactionStateHandler->refund($orderTransactionId, $context);
        }else {
          $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
        }

      }
    }
  }
  catch (\Exception $exception){
  }
  }

  private function getOrderTransactionIdByOrderId($orderId, $context)
  {
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
    $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
    if ($orderTransaction) {
      return $orderTransaction->getId();
    }
    return null;
  }
}