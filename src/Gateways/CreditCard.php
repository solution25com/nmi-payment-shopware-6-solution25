<?php declare(strict_types=1);

namespace NMIPayment\Gateways;

use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NmiTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CreditCard implements SynchronousPaymentHandlerInterface
{
    public function __construct(private readonly OrderTransactionStateHandler $transactionStateHandler, private readonly NmiTransactionService $nmiTransactionService, private readonly NMIConfigService $configService)
    {
    }

    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $authorizeOption = $this->configService->getConfig('authorizeAndCapture');
        $context = $salesChannelContext->getContext();
        $orderId = $transaction->getOrder()->getId();
        $paymentMethodName = $salesChannelContext->getPaymentMethod()->getTranslated()['name'];
        $nmiTransactionId = $dataBag->get('nmi_transaction_id') ?? null;
        $subscriptionTransactionId = $dataBag->get('nmi_is_subscription') ?? null;
        $isSubscription = (bool) $subscriptionTransactionId;

        if ($authorizeOption) {
            $this->transactionStateHandler->authorize($transaction->getOrderTransaction()->getId(), $context);
            $status = TransactionStatuses::AUTHORIZED->value;
            $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, $subscriptionTransactionId, $isSubscription, $status, $context);
        } else {
            $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
            $status = TransactionStatuses::PAID->value;
            $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, $subscriptionTransactionId, $isSubscription, $status, $context);
        }
    }
}
