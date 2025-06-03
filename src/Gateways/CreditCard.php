<?php

namespace NMIPayment\Gateways;

use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CreditCard implements SynchronousPaymentHandlerInterface
{
  private OrderTransactionStateHandler $transactionStateHandler;
  private NmiTransactionService $nmiTransactionService;
  private NMIConfigService  $configService;
  private LoggerInterface $logger;

  public function __construct(
    OrderTransactionStateHandler $transactionStateHandler,
    NmiTransactionService        $nmiTransactionService,
    NMIConfigService               $configService,
    LoggerInterface              $logger)
  {
    $this->transactionStateHandler = $transactionStateHandler;
    $this->nmiTransactionService = $nmiTransactionService;
    $this->configService = $configService;
    $this->logger = $logger;
  }

  public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
  {
    $authorizeOption = $this->configService->getConfig('authorizeAndCapture');
    $context = $salesChannelContext->getContext();
    $orderId = $transaction->getOrder()->getId();
    $paymentMethodName = $salesChannelContext->getPaymentMethod()->getTranslated()['name'];
    $nmiTransactionId = $dataBag->get('nmi_transaction_id') ?? null;
    $subscriptionTransactionId = $dataBag->get('nmi_is_subscription') ?? null;
    $isSubscription = (bool)$subscriptionTransactionId;
    $selectedBillingId = $dataBag->get('nmi_selected_billing_id') ?? null;


    if ($authorizeOption) {
      $this->transactionStateHandler->authorize($transaction->getOrderTransaction()->getId(), $context);
      $status = TransactionStatuses::AUTHORIZED->value;
      $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName,$nmiTransactionId, $subscriptionTransactionId,$isSubscription ,$status, $selectedBillingId, $context);

    } else {
      $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
      $status = TransactionStatuses::PAID->value;
      $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName,$nmiTransactionId, $subscriptionTransactionId,$isSubscription ,$status, $selectedBillingId, $context);

    }

  }

}