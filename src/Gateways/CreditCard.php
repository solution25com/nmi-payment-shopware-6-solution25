<?php

namespace NMIPayment\Gateways;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
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
    private EntityRepository $orderTransactionRepository;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private VaultedCustomerService $vaultedCustomerService;
    private NMIPaymentDataRequestService $nmiPaymentDataRequestService;


    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        NmiTransactionService $nmiTransactionService,
        NMIConfigService $configService,
        EntityRepository $orderTransactionRepository,
        NMIPaymentApiClient $nmiPaymentApiClient,
        VaultedCustomerService $vaultedCustomerService,
        NMIPaymentDataRequestService $nmiPaymentDataRequestService
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->nmiTransactionService = $nmiTransactionService;
        $this->configService = $configService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->nmiPaymentDataRequestService = $nmiPaymentDataRequestService;
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

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

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

        $orderId = $orderTransaction->getOrder()->getId();
        $paymentMethodName = $orderTransaction->getPaymentMethod()->getName();

        $this->transactionStateHandler->{$handlerMethod}($transaction->getOrderTransactionId(), $context);
        $this->nmiTransactionService->addTransaction($orderId, $paymentMethodName, $nmiTransactionId, null, false, $status, $selectedBillingId, $context);
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

        $securityKey = $this->configService->getConfig('privateKeyApi', $order->getSalesChannelId());
        $authorizeOnly = $this->configService->getConfig('authorizeAndCapture', $order->getSalesChannelId());
        $postData = [
        'security_key'     => $securityKey,
        'amount'           => $orderTransaction->getAmount()->getTotalPrice(),
        'currency'         => $mode == "sandbox" ? 'USD' : $order->getCurrency()->getIsoCode(),
        'type'             => $authorizeOnly ? 'auth' : 'sale',
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

        $customerVaultId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $order->getOrderCustomer()->getCustomerId());
        $billingId = null;
        $shouldVault = ($paymentData['saveCard'] ?? false) || $isSubscriptionCart;

        if (!empty($paymentData['customer_vault_id']) && !empty($paymentData['billing_id'])) {
            $postData['customer_vault_id'] = $paymentData['customer_vault_id'];
            $postData['billing_id'] = $paymentData['billing_id'];
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

            $existingBillingRecord = $this->vaultedCustomerService->getBillingIdByVaultedId($context, $customerVaultId);
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
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            return;
        }

        $nmiTransactionId = $response['transactionid'] ?? null;
        $this->transactionStateHandler->{$handlerMethod}($transaction->getOrderTransactionId(), $context);
        $this->nmiTransactionService->addTransaction($order->getId(), $orderTransaction->getPaymentMethod()->getName(), $nmiTransactionId, null, false, $status, $billingId, $context);
    }
}
