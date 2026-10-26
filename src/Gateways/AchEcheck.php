<?php

namespace NMIPayment\Gateways;

use NMIPayment\Service\AuthorizedTransactionSession;
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
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

class AchEcheck extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private NmiTransactionService $nmiTransactionService;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
    private EntityRepository $orderTransactionRepository;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        NmiTransactionService $nmiTransactionService,
        EntityRepository $orderTransactionRepository,
        NMIPaymentApiClient $nmiPaymentApiClient,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->nmiTransactionService = $nmiTransactionService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->logger = $logger;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $orderTransactionId = $transaction->getOrderTransactionId();

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('paymentMethod');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();
        $order = $orderTransaction->getOrder();
        $orderId = $orderTransaction->getOrderId();
        $paymentMethod = $orderTransaction->getPaymentMethod();
        $paymentMethodName = $paymentMethod ? ($paymentMethod->getTranslation('name') ?? $paymentMethod->getName()) : 'ACH/Echeck';
        $nmiTransactionId = $request->get('nmi_transaction_id') ?? null;

        if (trim((string) $nmiTransactionId) === '') {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException('NMI did not return an ACH transaction ID.');
        }

        if (!AuthorizedTransactionSession::consumeMatches($request, (string) $nmiTransactionId)) {
            $this->logger->warning('[pay] refused an ACH transaction id this session did not create', [
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
            ]);
            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw new RuntimeException('ACH transaction was not created in this session.');
        }

        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
        $verified = $this->nmiPaymentApiClient->queryTransaction((string) $nmiTransactionId);

        if ($verified === null) {
            $this->refuseOwnTransaction($orderTransactionId, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException('ACH transaction verification failed: could not query transaction.');
        }

        $allowedConditions = ['complete', 'pendingsettlement'];
        if (!in_array($verified['condition'], $allowedConditions, true)) {
            $this->refuseOwnTransaction($orderTransactionId, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException(sprintf('ACH transaction not in a successful state: %s', $verified['condition']));
        }

        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $actualAmount = (float) $verified['amount'];
        if (abs($actualAmount - $expectedAmount) > 0.01) {
            $this->refuseOwnTransaction($orderTransactionId, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException(sprintf('ACH transaction amount mismatch: expected %.2f, got %.2f', $expectedAmount, $actualAmount));
        }

        if ($verified['order_id'] !== '' && $verified['order_id'] !== $order->getOrderNumber()) {
            $this->logger->info('[pay] NMI order id differs from the order number', [
                'orderNumber' => $order->getOrderNumber(),
                'nmiOrderId' => $verified['order_id'],
            ]);
        }

        $status = TransactionStatuses::PAID->value;

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

        $this->transactionStateHandler->paid($orderTransactionId, $context);

        return null;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    /**
     * Refuses an ACH sale this session created: fails the Shopware transaction and voids the sale
     * at NMI, so a refused order does not keep the shopper's money.
     */
    private function refuseOwnTransaction(string $orderTransactionId, string $salesChannelId, string $nmiTransactionId, Context $context): void
    {
        $this->transactionStateHandler->fail($orderTransactionId, $context);

        try {
            $this->nmiPaymentApiClient->voidTransaction($salesChannelId, $nmiTransactionId, 'check');
        } catch (\Throwable $e) {
            $this->logger->error('[pay] void of refused ACH transaction threw', [
                'transactionId' => $nmiTransactionId,
                'exception_class' => $e::class,
            ]);
        }
    }
}
