<?php

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NMIPaymentDataRequestService
{
    public function __construct(private readonly NMIConfigService $nmiConfigService, private readonly NMIPaymentApiClient $nmiPaymentApiClient, private readonly LoggerInterface $logger) {}

    public function sendPaymentRequestToNMI(array $data, Cart $cart, SalesChannelContext $context): array
    {
        $paymentMethodType = $this->nmiConfigService->getConfig('authorizeAndCapture');
        $allLineItemsCart = $cart->getLineItems();

        $nonSubscriptionItems = [];
        $isSubscriptionCart = false;

        foreach ($allLineItemsCart as $lineItem) {
            $payload = $lineItem->getPayload();

            if (!empty($payload['isSubscription'])) {
                $isSubscriptionCart = true;
            }

            $productData = [
                'productNumber' => $payload['productNumber'] ?? null,
                'description' => $lineItem->getLabel(),
                'unitCost' => number_format($lineItem->getPrice()->getUnitPrice(), 4, '.', ''),
                'quantity' => $lineItem->getQuantity(),
                'totalAmount' => number_format($lineItem->getPrice()->getTotalPrice(), 2, '.', ''),
            ];

            $nonSubscriptionItems[] = $productData;
        }

        $responses = [];
        $customerVaultId = null;
        $postData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
            'amount' => $data['amount'],
            'currency' => 'USD',
            'type' => $paymentMethodType ? 'auth' : 'sale',
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'payment_token' => $data['token'] ?? null,
            'acu_enabled' => 'true',
            'line_items' => $nonSubscriptionItems,
        ];
        $billingId = null;
        if ($isSubscriptionCart || $data['saveCard']) {
            $billingId = Uuid::randomHex();
        }

        if ($isSubscriptionCart || $data['saveCard']) {
            $postData['customer_vault'] = 'add_customer';
            $postData['billing_id'] = $billingId;
        }

        $response = $this->nmiPaymentApiClient->createTransaction($postData);
        $this->logger->info('Payment Response -> '.json_encode($response));

        $processedResponse = $this->handleNMIResponse($response);

        if (!empty($processedResponse['customer_vault_id'])) {
            $customerVaultId = $processedResponse['customer_vault_id'];
            $processedResponse['billing_id'] = $billingId;
            $processedResponse['isSubscriptionCart'] = $isSubscriptionCart;
        }

        $responses['payment'] = $processedResponse;

        if (!$processedResponse['success']) {
            return $processedResponse;
        }

        return [
            'success' => true,
            'message' => 'Transaction processed successfully',
            'customer_vault_id' => $customerVaultId,
            'billing_id' => $billingId,
            'responses' => $responses,
        ];
    }

    public function sendPaymentRequestToNMIACHECK(array $data): array
    {
        $postData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
            'type' => 'sale',
            'payment' => 'check',
            'amount' => $data['amount'],
            'checkname' => $data['checkname'],
            'checkaba' => $data['checkaba'],
            'checkaccount' => $data['checkaccount'],
            'payment_token' => $data['token'],
        ];

        $response = $this->nmiPaymentApiClient->createTransaction($postData);

        $this->logger->alert(json_encode($postData));
        $this->logger->info(json_encode($response));

        return $this->handleNMIResponse($response);
    }

    public function handleNMIResponse(array $response): array
    {
        if (isset($response['response']) && '1' === $response['response']) {
            return [
                'success' => true,
                'message' => 'Payment successful!',
                'transaction_id' => $response['transactionid'],
                'customer_vault_id' => $response['customer_vault_id'] ?? null,
                'billing_id' => $response['billing_id'] ?? null,
                'isSubscriptionCart' => $response['isSubscriptionCart'] ?? false,
            ];
        }

        //            $this->logger->warning('Payment failed', ['response' => $response]);
        return [
            'success' => false,
            'message' => 'Payment failed: '.($response['responsetext'] ?? 'Unknown error'),
        ];
    }
}
