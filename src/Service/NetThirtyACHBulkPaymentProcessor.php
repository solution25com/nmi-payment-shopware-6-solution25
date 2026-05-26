<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Installer\OrderStateInstaller;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Psr\Log\LoggerInterface;

class NetThirtyACHBulkPaymentProcessor
{
    public function __construct(
        private readonly NMIPaymentApiClient $nmiApiClient,
        private readonly NMIConfigService $nmiConfigService,
        private readonly NMIACHPaymentDataRequestService $nmiACHPaymentDataRequestService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processBulkPaymentWithACH(
        array $paymentData,
        array $orderIds,
        Context $context
    ): array {
        $results = [];
        $customerVaultId = null;
        $billingId = null;
        $isFirstOrder = true;

        foreach ($orderIds as $orderId) {
            try {
                $result = $this->processSingleOrder(
                    $orderId,
                    $paymentData,
                    $context,
                    $isFirstOrder,
                    $customerVaultId,
                    $billingId
                );

                $results[] = $result;

                if ($result['success'] && $isFirstOrder) {
                    if (!empty($result['customer_vault_id'])) {
                        $customerVaultId = $result['customer_vault_id'];
                    }
                    if (!empty($result['billing_id'])) {
                        $billingId = $result['billing_id'];
                    }
                    $isFirstOrder = false;
                    $paymentData['token'] = null;
                }
            } catch (\Throwable $e) {
                $this->logger->error('[NET30][ACH] Bulk payment failed for order', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $results[] = [
                    'order_id' => $orderId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];

                if ($isFirstOrder) {
                    $isFirstOrder = false;
                }
            }
        }

        return $results;
    }

    private function processSingleOrder(
        string $orderId,
        array $paymentData,
        Context $context,
        bool $isFirstOrder = true,
        ?string $customerVaultId = null,
        ?string $billingId = null
    ): array {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('transactions')
            ->addAssociation('transactions.stateMachineState')
            ->addAssociation('orderCustomer')
            ->addAssociation('billingAddress')
            ->addAssociation('billingAddress.country')
            ->addAssociation('billingAddress.countryState')
            ->addAssociation('deliveries.shippingOrderAddress')
            ->addAssociation('deliveries.shippingOrderAddress.country')
            ->addAssociation('deliveries.shippingOrderAddress.countryState')
            ->addAssociation('lineItems');

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new \RuntimeException('Order not found: ' . $orderId);
        }

        $transaction = $order->getTransactions()?->first();
        if (!$transaction instanceof OrderTransactionEntity) {
            throw new \RuntimeException('Order transaction not found for order: ' . $order->getOrderNumber());
        }

        $currentState = $transaction->getStateMachineState()?->getTechnicalName();
        if ($currentState === 'paid') {
            return [
                'order_id' => $orderId,
                'order_number' => $order->getOrderNumber(),
                'success' => true,
                'message' => 'Order already paid',
                'skipped' => true,
            ];
        }

        if (
            $currentState !== OrderStateInstaller::STATE_TECHNICAL_NAME
            && $currentState !== OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
        ) {
            throw new \RuntimeException('Order is not in an unpaid Net 30 status (current: ' . $currentState . ')');
        }

        $salesChannelId = $order->getSalesChannelId() ?? '';
        $this->nmiApiClient->initializeForSalesChannel($salesChannelId);

        $amount = $order->getPrice()->getTotalPrice();
        $securityKey = $this->getSecurityKey($salesChannelId);
        $authorizeOnly = (bool) $this->nmiConfigService->getConfig('authorizeAndCapture', $salesChannelId);

        $billingAddress  = $order->getBillingAddress();
        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();
        $orderCustomer   = $order->getOrderCustomer();

        $lineItems = [];
        foreach ($order->getLineItems() as $lineItem) {
            $lineItems[] = [
                'productNumber' => $lineItem->getPayload()['productNumber'] ?? '',
                'description' => $lineItem->getLabel(),
                'unitCost' => number_format($lineItem->getPrice()->getUnitPrice(), 4, '.', ''),
                'quantity' => $lineItem->getQuantity(),
                'totalAmount' => number_format($lineItem->getPrice()->getTotalPrice(), 2, '.', ''),
            ];
        }

        $uniqueOrderId = $order->getOrderNumber() . '-' . time() . '-' . substr($orderId, 0, 8);

        $postData = [
            'security_key'       => $securityKey,
            'amount'             => $amount,
            'currency'           => $order->getCurrency()?->getIsoCode() ?? 'USD',
            'type'               => $authorizeOnly ? 'auth' : 'sale',
            'orderid'            => $uniqueOrderId,
            'payment'            => 'check',
            'first_name'         => $paymentData['first_name'] ?? $orderCustomer->getFirstName(),
            'last_name'          => $paymentData['last_name'] ?? $orderCustomer->getLastName(),
            'address1'           => $paymentData['address1'] ?? ($billingAddress ? $billingAddress->getStreet() : ''),
            'city'               => $paymentData['city'] ?? ($billingAddress ? $billingAddress->getCity() : ''),
            'state'              => $paymentData['state'] ?? ($billingAddress && $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getShortCode() : ''),
            'zip'                => $paymentData['zip'] ?? ($billingAddress ? $billingAddress->getZipcode() : ''),
            'country'            => $paymentData['country'] ?? ($billingAddress ? $billingAddress->getCountry()->getIso() : 'US'),
            'phone'              => $billingAddress ? ($billingAddress->getPhoneNumber() ?? '') : '',
            'email'              => $orderCustomer->getEmail(),
            'shipping_firstname' => $shippingAddress?->getFirstName() ?? ($billingAddress?->getFirstName() ?? ''),
            'shipping_lastname'  => $shippingAddress?->getLastName()  ?? ($billingAddress?->getLastName()  ?? ''),
            'shipping_address1'  => $shippingAddress?->getStreet()    ?? ($billingAddress?->getStreet()    ?? ''),
            'shipping_city'      => $shippingAddress?->getCity()      ?? ($billingAddress?->getCity()      ?? ''),
            'shipping_zip'       => $shippingAddress?->getZipcode()   ?? ($billingAddress?->getZipcode()   ?? ''),
            'shipping_state'     => $shippingAddress?->getCountryState()?->getShortCode() ?? ($billingAddress?->getCountryState()?->getShortCode() ?? ''),
            'shipping_country'   => $shippingAddress?->getCountry()?->getIso()            ?? ($billingAddress?->getCountry()?->getIso()            ?? ''),
            'shipping_email'     => $orderCustomer->getEmail(),
            'line_items'         => $lineItems,
        ];

        if ($customerVaultId && $billingId) {
            $postData['customer_vault_id'] = $customerVaultId;
            $postData['billing_id'] = $billingId;
        } elseif ($isFirstOrder && !empty($paymentData['token'])) {
            $postData['payment_token'] = $paymentData['token'];
            $postData['checkname'] = $paymentData['checkname'] ?? '';
            $postData['checkaba'] = $paymentData['checkaba'] ?? '';
            $postData['checkaccount'] = $paymentData['checkaccount'] ?? '';
            $postData['account_holder_type'] = $paymentData['account_holder_type'] ?? 'personal';
            $postData['account_type'] = $paymentData['account_type'] ?? 'checking';
            $postData['customer_vault'] = 'add_customer';
            $billingId = Uuid::randomHex();
            $postData['billing_id'] = $billingId;
        } else {
            if ($isFirstOrder) {
                throw new \RuntimeException('Missing payment token for first order');
            } else {
                throw new \RuntimeException('Missing vault information for subsequent orders.');
            }
        }

        $response = $this->nmiApiClient->createTransaction($postData);

        if (!is_array($response) || empty($response)) {
            throw new \RuntimeException('Invalid response from payment gateway');
        }

        if (!isset($response['response']) || $response['response'] !== '1') {
            $message = $response['responsetext'] ?? 'Payment failed';
            throw new \RuntimeException('Payment failed: ' . $message);
        }

        $transactionId = $response['transactionid'] ?? null;
        $returnedCustomerVaultId = $response['customer_vault_id'] ?? null;

        if ($isFirstOrder && !$returnedCustomerVaultId) {
            throw new \RuntimeException('Vault creation failed: customer_vault_id not returned from payment gateway');
        }

        $this->markOrderPaidOrAuthorized($order, $transaction, $transactionId, $authorizeOnly, $context);

        $result = [
            'order_id' => $orderId,
            'order_number' => $order->getOrderNumber(),
            'success' => true,
            'transaction_id' => $transactionId,
        ];

        if ($isFirstOrder && $returnedCustomerVaultId) {
            $result['customer_vault_id'] = $returnedCustomerVaultId;
            $result['billing_id'] = $billingId;
        }

        return $result;
    }

    private function getSecurityKey(string $salesChannelId): string
    {
        $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
        $isLive = $mode === 'live';

        $key = $this->nmiConfigService->getConfig(
            $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
            $salesChannelId
        );

        if (!$key) {
            throw new \RuntimeException('NMI private key is not configured for this sales channel.');
        }

        return $key;
    }

    private function markOrderPaidOrAuthorized(
        OrderEntity $order,
        OrderTransactionEntity $transaction,
        ?string $transactionId,
        bool $authorizeOnly,
        Context $context
    ): void {
        if ($authorizeOnly) {
            $this->transactionStateHandler->authorize($transaction->getId(), $context);
        } else {
            $this->transactionStateHandler->paid($transaction->getId(), $context);
        }

        $customFields = $order->getCustomFields() ?? [];
        $customFields[NetThirtyFields::PAYMENT_COMPLETED] = true;
        $customFields[NetThirtyFields::PAYMENT_COMPLETED_AT] = (new \DateTime())->format('Y-m-d H:i:s');
        $customFields[NetThirtyFields::TRANSACTION_ID] = $transactionId;
        $customFields[NetThirtyFields::PAYMENT_METHOD] = 'ach';

        $order->setCustomFields($customFields);

        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => $customFields,
        ]], $context);
    }
}
