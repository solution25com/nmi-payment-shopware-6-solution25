<?php

namespace NMIPayment\Gateways;

use NMIPayment\Service\AuthorizedTransactionSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Uuid\Uuid;
use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NMIPaymentDataRequestService;
use NMIPayment\Service\NmiTransactionService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use NMIPayment\Service\VaultedCustomerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class CreditCard extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private NmiTransactionService $nmiTransactionService;
    private NMIConfigService $configService;
    /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
    private EntityRepository $orderTransactionRepository;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private VaultedCustomerService $vaultedCustomerService;
    private NMIPaymentDataRequestService $nmiPaymentDataRequestService;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        NmiTransactionService $nmiTransactionService,
        NMIConfigService $configService,
        EntityRepository $orderTransactionRepository,
        NMIPaymentApiClient $nmiPaymentApiClient,
        VaultedCustomerService $vaultedCustomerService,
        NMIPaymentDataRequestService $nmiPaymentDataRequestService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->nmiTransactionService = $nmiTransactionService;
        $this->configService = $configService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->nmiPaymentDataRequestService = $nmiPaymentDataRequestService;
        $this->logger = $logger;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $flow = $this->configService->getConfig('flow', $salesChannelId);

        $authorizeOption = $this->configService->getConfig('authorizeAndCapture', $salesChannelId);
        $status = $authorizeOption ? TransactionStatuses::AUTHORIZED->value : TransactionStatuses::PAID->value;
        $handlerMethod = $authorizeOption ? 'authorize' : 'paid';

        $transactionId = $transaction->getOrderTransactionId();
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.billingAddress');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        if ($flow == 'payment_order') {
            $this->paymentFirstFlow($request, $transaction, $orderTransaction, $handlerMethod, $status, $context);
        } else {
            $this->orderFirstFlow($request, $transaction, $orderTransaction, $handlerMethod, $status, $context);
        }
        return null;
    }

    private function paymentFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, $handlerMethod, $status, Context $context): void
    {
        $nmiTransactionId = $request->getPayload()->get('nmi_transaction_id');
        $selectedBillingId = $request->getPayload()->get('nmi_selected_billing_id') ?? null;

        $order = $orderTransaction->getOrder();
        $orderId = $order->getId();
        $paymentMethodName = $orderTransaction->getPaymentMethod()->getName();

        if (trim((string) $nmiTransactionId) === '') {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new RuntimeException('NMI did not return a transaction ID.');
        }

        if (!AuthorizedTransactionSession::consumeMatches($request, (string) $nmiTransactionId)) {
            $this->logger->warning('[pay] refused a transaction id this session did not authorize', [
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
            ]);
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new RuntimeException('NMI transaction was not authorized in this session.');
        }

        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());
        $verified = $this->nmiPaymentApiClient->queryTransaction((string) $nmiTransactionId);

        if ($verified === null) {
            $this->refuseOwnTransaction($transaction, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException('NMI transaction verification failed: could not query transaction.');
        }

        $allowedConditions = $handlerMethod === 'authorize' ? ['pending'] : ['complete', 'pendingsettlement'];
        if (!in_array($verified['condition'], $allowedConditions, true)) {
            $this->refuseOwnTransaction($transaction, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException(sprintf('NMI transaction not in a successful state: %s', $verified['condition']));
        }

        $expectedAmount = $orderTransaction->getAmount()->getTotalPrice();
        $actualAmount = (float) $verified['amount'];
        if (abs($actualAmount - $expectedAmount) > 0.01) {
            $this->refuseOwnTransaction($transaction, $order->getSalesChannelId(), (string) $nmiTransactionId, $context);
            throw new RuntimeException(sprintf('NMI transaction amount mismatch: expected %.2f, got %.2f', $expectedAmount, $actualAmount));
        }

        if ($verified['order_id'] !== '' && $verified['order_id'] !== $order->getOrderNumber()) {
            $this->logger->info('[pay] NMI order id differs from the order number', [
                'orderNumber' => $order->getOrderNumber(),
                'nmiOrderId' => $verified['order_id'],
            ]);
        }

        $this->nmiTransactionService->addTransaction(
            $orderId,
            $paymentMethodName,
            $nmiTransactionId,
            null,
            false,
            $status,
            $selectedBillingId,
            $context,
            (float) $verified['amount']
        );
        $this->transactionStateHandler->{$handlerMethod}($transaction->getOrderTransactionId(), $context);
    }

    private function orderFirstFlow(Request $request, PaymentTransactionStruct $transaction, OrderTransactionEntity $orderTransaction, $handlerMethod, $status, Context $context): void
    {
        $order = $orderTransaction->getOrder();
        $salesChannelContext = $request->attributes->get('sw-sales-channel-context');
        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $mode = $this->configService->getConfig('mode', $salesChannelId);

        $this->nmiPaymentApiClient->initializeForSalesChannel($order->getSalesChannelId());

        $billingAddress = $order->getBillingAddress();
        $delivery = $order->getDeliveries()->first();
        $shippingAddress = $delivery ? $delivery->getShippingOrderAddress() : $billingAddress;

        $paymentData = json_decode($request->request->get('nmiPaymentData'), true);
        if (!$paymentData) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new \RuntimeException('Missing payment data for order-first flow');
        }

        $nonSubscriptionItems = [];
        $isSubscriptionCart = false;
        foreach ($order->getLineItems() as $lineItem) {
            $payload = $lineItem->getPayload();
            if (!empty($payload['isSubscription'])) {
                $isSubscriptionCart = true;
            }
            $nonSubscriptionItems[] = [
            'productNumber' => $payload['productNumber'] ?? null,
            'description'   => $lineItem->getLabel(),
            'unitCost'      => number_format($lineItem->getPrice()->getUnitPrice(), 4, '.', ''),
            'quantity'      => $lineItem->getQuantity(),
            'totalAmount'   => number_format($lineItem->getPrice()->getTotalPrice(), 2, '.', ''),
            ];
        }

        $orderMode = $this->configService->getConfig('mode', $order->getSalesChannelId());
        $securityKey = $this->configService->getConfig(
            $orderMode === 'live' ? 'privateKeyApiLive' : 'privateKeyApi',
            $order->getSalesChannelId()
        );
        $authorizeOnly = $this->configService->getConfig('authorizeAndCapture', $order->getSalesChannelId());
        $postData = [
        'security_key'     => $securityKey,
        'amount'           => $orderTransaction->getAmount()->getTotalPrice(),
        'currency'         => $mode == "sandbox" ? 'USD' : $order->getCurrency()->getIsoCode(),
        'type'             => $authorizeOnly ? 'auth' : 'sale',
        'orderid'          => $order->getOrderNumber(),
        'first_name'       => $billingAddress->getFirstName(),
        'last_name'        => $billingAddress->getLastName(),
        'address1'         => $billingAddress->getStreet(),
        'city'             => $billingAddress->getCity(),
        'state'            => $billingAddress->getCountryState()?->getShortCode(),
        'zip'              => $billingAddress->getZipcode(),
        'country'          => $billingAddress->getCountry()?->getIso(),
        'phone'            => $billingAddress->getPhoneNumber(),
        'email'            => $order->getOrderCustomer()->getEmail(),
        'shipping_firstname' => $shippingAddress->getFirstName(),
        'shipping_lastname'  => $shippingAddress->getLastName(),
        'shipping_address1'  => $shippingAddress->getStreet(),
        'shipping_city'      => $shippingAddress->getCity(),
        'shipping_state'     => $shippingAddress->getCountryState()?->getShortCode(),
        'shipping_zip'       => $shippingAddress->getZipcode(),
        'shipping_country'   => $shippingAddress->getCountry()?->getIso(),
        'shipping_email'     => $order->getOrderCustomer()->getEmail(),
        'acu_enabled'        => 'true',
        'line_items'         => $nonSubscriptionItems,
        ];

        $orderCustomerId = (string) $order->getOrderCustomer()->getCustomerId();
        $customerVaultId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $orderCustomerId);
        $billingId = null;
        $shouldVault = ($paymentData['saveCard'] ?? false) || $isSubscriptionCart;

        if (!empty($paymentData['customer_vault_id']) && !empty($paymentData['billing_id'])) {
            $ownsCard = \is_scalar($paymentData['customer_vault_id'])
                && \is_scalar($paymentData['billing_id'])
                && $this->vaultedCustomerService->customerOwnsCard(
                    $context,
                    $orderCustomerId,
                    (string) $paymentData['customer_vault_id'],
                    (string) $paymentData['billing_id']
                );
            if (!$ownsCard) {
                $this->logger->warning('[pay] refused a saved card the order customer does not own', [
                    'orderId' => $order->getId(),
                    'customerId' => $orderCustomerId,
                ]);
                throw PaymentException::syncProcessInterrupted($transaction->getOrderTransactionId(), 'The selected saved card could not be used.');
            }

            $postData['customer_vault_id'] = (string) $paymentData['customer_vault_id'];
            $postData['billing_id'] = (string) $paymentData['billing_id'];
        } else {
            if (empty($paymentData['token'])) {
                $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
                throw new \RuntimeException('Missing card token for order-first flow');
            }
            $postData['payment_token'] = $paymentData['token'];

            if ($shouldVault) {
                $billingId = Uuid::randomHex();
                if (empty($customerVaultId)) {
                    $postData['customer_vault'] = 'add_customer';
                    $postData['billing_id']    = $billingId;
                } else {
                    $postData['customer_vault_id'] = $customerVaultId;
                    $postData['customer_vault']    = 'add_billing';
                    $postData['billing_id']       = $billingId;
                }
            }
        }

        $response = $this->nmiPaymentApiClient->createTransaction($postData);
        $processedResponse = $this->nmiPaymentDataRequestService->handleNMIResponse($response);

        if (!empty($processedResponse['customer_vault_id']) && $billingId !== null) {
            $customerVaultId = $processedResponse['customer_vault_id'];
            $processedResponse['billing_id'] = $billingId;
            $processedResponse['isSubscriptionCart'] = $isSubscriptionCart;

            $existingBillingRecord = $this->vaultedCustomerService->getBillingIdByVaultedId($context, $customerVaultId, $orderCustomerId);
            $existingBillingData = $existingBillingRecord ? $existingBillingRecord->getBillingId() : null;
            $billingArray = !empty($existingBillingData) ? json_decode($existingBillingData, true) : [];

            if (!is_array($billingArray)) {
                $billingArray = [];
            }

            $newBillingData = [
            'billingId'  => $billingId,
            'cardType'   => $paymentData['card_type'] ?? null,
            'firstName'  => $billingAddress->getFirstName(),
            'lastName'   => $billingAddress->getLastName(),
            'lastDigits' => $paymentData['ccnumber'] ?? null,
            'ccexp'      => $paymentData['ccexp'] ?? null,
            ];

            $billingArray[] = $newBillingData;

            $this->vaultedCustomerService->store($salesChannelContext, $customerVaultId, 'null', json_encode($billingArray), $billingId);
        }

        if (empty($processedResponse['success'])) {
            $this->logger->info('[pay] order-first charge declined', [
                'orderId' => $order->getId(),
                'gatewayMessage' => $processedResponse['message'] ?? null,
            ]);
            throw PaymentException::syncProcessInterrupted($transaction->getOrderTransactionId(), 'The payment was declined.');
        }

        $nmiTransactionId = $response['transactionid'] ?? null;
        if (trim((string) $nmiTransactionId) === '') {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new RuntimeException('NMI returned success without a transaction ID.');
        }

        $this->nmiTransactionService->addTransaction(
            $order->getId(),
            $orderTransaction->getPaymentMethod()->getName(),
            $nmiTransactionId,
            null,
            false,
            $status,
            $billingId,
            $context,
            (float) $orderTransaction->getAmount()->getTotalPrice()
        );
        $this->transactionStateHandler->{$handlerMethod}($transaction->getOrderTransactionId(), $context);
    }

    /**
     * Refuses a transaction this session's own auth route created: fails the Shopware transaction
     * and voids it at NMI, so a refused auth does not stay on hold on the shopper's card.
     */
    private function refuseOwnTransaction(PaymentTransactionStruct $transaction, string $salesChannelId, string $nmiTransactionId, Context $context): void
    {
        $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);

        try {
            $this->nmiPaymentApiClient->voidTransaction($salesChannelId, $nmiTransactionId);
        } catch (\Throwable $e) {
            $this->logger->error('[pay] void of refused transaction threw', [
                'transactionId' => $nmiTransactionId,
                'exception_class' => $e::class,
            ]);
        }
    }
}
