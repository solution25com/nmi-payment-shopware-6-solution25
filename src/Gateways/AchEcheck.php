<?php

namespace NMIPayment\Gateways;

use RuntimeException;
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
use NMIPayment\Service\NMIPaymentApiClient;
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
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        NmiTransactionService $nmiTransactionService,
        NMIConfigService $configService,
        EntityRepository $orderTransactionRepository,
        NMIPaymentApiClient $nmiPaymentApiClient,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->nmiTransactionService = $nmiTransactionService;
        $this->configService = $configService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->logger = $logger;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        $order = $orderTransaction->getOrder();
        $orderId = $orderTransaction->getOrderId();
        $paymentMethod = $orderTransaction->getPaymentMethod();
        $paymentMethodName = $paymentMethod ? ($paymentMethod->getTranslation('name') ?? $paymentMethod->getName()) : 'ACH/Echeck';
        $nmiTransactionId = $request->get('nmi_transaction_id') ?? null;

        if (trim((string) $nmiTransactionId) === '') {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException('NMI did not return an ACH transaction ID.');
        }

        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
        $verified = $this->nmiPaymentApiClient->queryTransaction((string) $nmiTransactionId);

        if ($verified === null) {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException('ACH transaction verification failed: could not query transaction.');
        }

        $allowedConditions = ['complete', 'pendingsettlement'];
        if (!in_array($verified['condition'], $allowedConditions, true)) {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException(sprintf('ACH transaction not in a successful state: %s', $verified['condition']));
        }

        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $actualAmount = (float) $verified['amount'];
        if (abs($actualAmount - $expectedAmount) > 0.01) {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException(sprintf('ACH transaction amount mismatch: expected %.2f, got %.2f', $expectedAmount, $actualAmount));
        }

        if ($verified['order_id'] !== '' && $verified['order_id'] !== $order->getOrderNumber()) {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException('ACH transaction order ID mismatch.');
        }

        $authorizeOption = $this->configService->getConfig('authorizeAndCapture', $salesChannelId);

        if ($authorizeOption) {
            $status = TransactionStatuses::AUTHORIZED->value;
        } else {
            $status = TransactionStatuses::PAID->value;
        }

        $this->nmiTransactionService->addTransaction(
            $orderId,
            $paymentMethodName,
            $nmiTransactionId,
            null,
            false,
            $status,
            null,
            $context,
            (float) $verified['amount']
        );

        if ($authorizeOption) {
            $this->transactionStateHandler->authorize($orderTransactionId, $context);
        } else {
            $this->transactionStateHandler->paid($orderTransactionId, $context);
        }

        return null;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }
}
