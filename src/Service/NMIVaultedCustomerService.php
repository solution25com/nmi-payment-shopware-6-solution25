<?php

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NMIVaultedCustomerService
{
    public function __construct(private readonly NMIConfigService $nmiConfigService, private readonly NMIPaymentApiClient $nmiPaymentApiClient, private readonly NMIPaymentDataRequestService $nmiPaymentDataRequestService, private readonly VaultedCustomerService $vaultedCustomerService, private readonly LoggerInterface $logger) {}

    public function vaultedCapture(array $data, Cart $cart, SalesChannelContext $context): array
    {
        $paymentMethodType = $this->nmiConfigService->getConfig('authorizeAndCapture');
        $getDefaultBilling = $this->vaultedCustomerService->getDefaultBillingId($context->getContext(), $data['customer_vault_id']);
        $this->logger->info('Starting vaulted Capture');

        $billingIds = json_decode((string) $getDefaultBilling, true);

        $allLineItemsCart = $cart->getLineItems();
        $allItems = [];

        $isSubscriptionCart = false;

        foreach ($allLineItemsCart as $lineItem) {
            $payload = $lineItem->getPayload();
            $isSubscription = $payload['isSubscription'] ?? false;

            $productData = [
                'productNumber' => $payload['productNumber'] ?? null,
                'description' => $lineItem->getLabel(),
                'unitCost' => number_format($lineItem->getPrice()->getUnitPrice(), 4, '.', ''),
                'quantity' => $lineItem->getQuantity(),
                'totalAmount' => number_format($lineItem->getPrice()->getTotalPrice(), 2, '.', ''),
            ];

            if ($isSubscription) {
                $isSubscriptionCart = true;
            }

            $allItems[] = $productData;
        }

        $postData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi') ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => 'USD',
            'type' => $paymentMethodType ? 'auth' : 'sale',
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'customer_vault_id' => $data['customer_vault_id'] ?? null,
            'acu_enabled' => 'true',
            'line_items' => $allItems,
        ];
        $billingIds = json_decode((string) $getDefaultBilling, true);
        $defaultBId = $billingIds[0];

        if ($data['customer_vault_id']) {
            $postData['billing_id'] = $data['billing_id'] ?? $defaultBId['billingId'];
        }

        $response = $this->nmiPaymentApiClient->createTransaction($postData);
        $this->logger->info('Vaulted Capture Response -> '.json_encode($response));

        $processedResponse = $this->nmiPaymentDataRequestService->handleNMIResponse($response);

        $customerVaultId = null;
        if (!empty($processedResponse['customer_vault_id'])) {
            $customerVaultId = $processedResponse['customer_vault_id'];
        }

        if (!$processedResponse['success']) {
            return $processedResponse;
        }

        return [
            'success' => true,
            'message' => 'Vaulted capture processed successfully',
            'customer_vault_id' => $customerVaultId,
            'responses' => [
                'payment' => $processedResponse,
            ],
        ];
    }

    public function addMultipleCards(array $data, Cart $cart, SalesChannelContext $context): array
    {
        $billingId = Uuid::randomHex();
        $postUpdateData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi') ?? null,
            'customer_vault' => 'add_billing',
            'customer_vault_id' => $data['vaulted_customer_id'],
            'billing_id' => $billingId,
            'payment_token' => $data['token'] ?? null,
        ];

        $response = $this->nmiPaymentApiClient->createTransaction($postUpdateData);
        $this->logger->info('Billing method(addMultipleCards) response: '.json_encode($response));

        if ('1' === $response['response']) {
            return [
                'success' => true,
                'message' => 'Customer billing added.',
                'billingId' => $billingId,
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to add customer billing data from vault: '.($response['responsetext'] ?? 'Unknown error'),
        ];
    }

    public function sendDataToGetVaultedCustomer(array $data, SalesChannelContext $context): array
    {
        $this->logger->info('getting vaulted customer data  --------->');

        $vaultedCustomerId = $data['customer_vault_id'] ?? null;
        if (!$vaultedCustomerId) {
            return ['success' => false, 'message' => 'Missing vaulted customer ID'];
        }

        $response = $this->vaultedCustomerService->getDefaultBillingId($context->getContext(), $vaultedCustomerId);

        $responseData = json_decode((string) $response, true);

        if (!empty($responseData) && isset($responseData[0])) {
            $customerData = $responseData[0];

            $firstName = $customerData['firstName'];
            $lastName = $customerData['lastName'];
            $ccType = $customerData['cardType'];
            $ccNumber = $customerData['lastDigits'];

            $this->logger->info("Extracted data - First Name: {$firstName}, Last Name: {$lastName}, CC Type: {$ccType}, CC Number: {$ccNumber}");

            return [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'cc_type' => $ccType,
                'cc_number' => $ccNumber,
            ];
        }

        return ['success' => false, 'message' => 'No customer data found'];
    }

    public function deleteVaultedCustomerData(string $customerVaultId, SalesChannelContext $context): array
    {
        $this->logger->info('Starting delete process for vaulted customer with ID: '.$customerVaultId);

        $postData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
            'customer_vault' => 'delete_customer',
            'customer_vault_id' => $customerVaultId,
        ];

        $response = $this->nmiPaymentApiClient->createTransaction($postData);

        $this->logger->info('Delete response: '.json_encode($response));

        if ('1' === $response['response']) {
            return [
                'success' => true,
                'message' => 'Customer data successfully deleted from vault.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to delete customer data from vault: '.($response['responsetext'] ?? 'Unknown error'),
        ];
    }

    public function deleteBillingRecord(string $customerVaultId, string $billingId, SalesChannelContext $context): array
    {
        $postData = [
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
            'customer_vault' => 'delete_billing',
            'customer_vault_id' => $customerVaultId,
            'billing_id' => $billingId,
        ];

        $response = $this->nmiPaymentApiClient->createTransaction($postData);

        $this->logger->info('Delete response: '.json_encode($response));

        if ('1' === $response['response']) {
            $this->vaultedCustomerService->deleteBillingFromDB($context, $customerVaultId, $billingId);

            return [
                'success' => true,
                'message' => 'Billing data successfully deleted from vault.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to delete billing data from vault: '.($response['responsetext'] ?? 'Unknown error'),
        ];
    }

    public function setDefaultBilling(string $customerVaultId, string $billingId, SalesChannelContext $context): array
    {
        $this->vaultedCustomerService->setDefaultBilling($context, $customerVaultId, $billingId);

        return [
            'success' => true,
            'message' => 'Billing data successfully deleted from vault.',
        ];
    }
}
