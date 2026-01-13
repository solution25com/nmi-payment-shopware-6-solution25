<?php

namespace NMIPayment\Gateways;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Framework\Context;
use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class AchEcheck extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private NmiTransactionService $nmiTransactionService;
    private NMIConfigService $configService;
    private EntityRepository $orderTransactionRepository;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        NmiTransactionService $nmiTransactionService,
        NMIConfigService $configService,
        EntityRepository $orderTransactionRepository,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->nmiTransactionService = $nmiTransactionService;
        $this->configService = $configService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $authorizeOption = $this->configService->getConfig('authorizeAndCapture');
        $orderTransactionId = $transaction->getOrderTransactionId();

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('paymentMethod');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        $orderId = $orderTransaction->getOrderId();
        $paymentMethod = $orderTransaction->getPaymentMethod();
        $paymentMethodName = $paymentMethod ? ($paymentMethod->getTranslation('name') ?? $paymentMethod->getName()) : 'ACH/Echeck';
        $nmiTransactionId = $request->get('nmi_transaction_id') ?? null;

        if ($authorizeOption) {
            $this->transactionStateHandler->authorize($transaction->getOrderTransactionId(), $context);
            $status = TransactionStatuses::AUTHORIZED->value;
            $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, null, false, $status, null, $context);
        } else {
            $this->transactionStateHandler->paid($transaction->getOrderTransactionId(), $context);
            $status = TransactionStatuses::PAID->value;
            $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, null, false, $status, null, $context);
        }

        return null;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }
}
