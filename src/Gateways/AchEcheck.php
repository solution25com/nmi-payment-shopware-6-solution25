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

class AchEcheck implements SynchronousPaymentHandlerInterface
{
    public function __construct(private readonly OrderTransactionStateHandler $transactionStateHandler, private readonly NmiTransactionService $nmiTransactionService)
    {
    }

    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $orderId = $transaction->getOrder()->getId();
        $paymentMethodName = $salesChannelContext->getPaymentMethod()->getName();
        $nmiTransactionId = $dataBag->get('nmi_transaction_id') ?? null;
        $context = $salesChannelContext->getContext();

        $this->transactionStateHandler->processUnconfirmed($transaction->getOrderTransaction()->getId(), $context);
        $status = TransactionStatuses::UNCONFIRMED->value;
        $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, null, false, $status ,$context);
    }
}
