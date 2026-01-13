<?php

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NMIACHPaymentDataRequestService
{
  private NMIConfigService $nmiConfigService;
  private NMIPaymentApiClient $nmiPaymentApiClient;
  private NumberRangeValueGeneratorInterface $numberRangeGenerator;
  private LoggerInterface $logger;

  public function __construct(
    NMIConfigService $nmiConfigService,
    NmiPaymentApiClient $nmiPaymentApiClient,
    NumberRangeValueGeneratorInterface $numberRangeGenerator,
    LoggerInterface $logger)
  {
    $this->nmiConfigService = $nmiConfigService;
    $this->nmiPaymentApiClient = $nmiPaymentApiClient;
    $this->numberRangeGenerator = $numberRangeGenerator;
    $this->logger = $logger;
  }

  public function sendACHPaymentRequestToNMI(array $data, Cart $cart, SalesChannelContext $context): array
  {
    $salesChannelId = $context->getSalesChannel()->getId();
    $this->nmiPaymentApiClient->initializeForSalesChannel($salesChannelId);
    $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
    $isLive = $mode === 'live';
    $securityKey = $this->nmiConfigService->getConfig(
      $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
      $salesChannelId
    );

    $customer = $context->getCustomer();
    if (!$customer) {
      return ['success' => false, 'message' => 'Customer not found'];
    }

    $billingAddress = $customer->getActiveBillingAddress();
    $shippingAddress = $customer->getActiveShippingAddress();

    $paymentMethodType = $this->nmiConfigService->getConfig('authorizeAndCapture');
    $allLineItemsCart = $cart->getLineItems();
    $nonSubscriptionItems = [];

    foreach ($allLineItemsCart as $lineItem) {
      $payload = $lineItem->getPayload();
      $productData = [
        'productNumber' => $payload['productNumber'] ?? null,
        'description' => $lineItem->getLabel(),
        'unitCost' => number_format($lineItem->getPrice()->getUnitPrice(), 4, '.', ''),
        'quantity' => $lineItem->getQuantity(),
        'totalAmount' => number_format($lineItem->getPrice()->getTotalPrice(), 2, '.', ''),
      ];
      $nonSubscriptionItems[] = $productData;
    }

    $predictedOrderNumber = $this->numberRangeGenerator->getValue(
      'order',
      $context->getContext(),
      $salesChannelId,
      true
    );

    $postData = [
      'security_key' => $securityKey,
      'amount' => $data['amount'],
      'currency' => 'USD',
      'type' => $paymentMethodType ? 'auth' : 'sale',
      'orderid' => $predictedOrderNumber,
      'payment' => 'check',
      'payment_token' => $data['token'] ?? null,
      'checkname' => $data['checkname'] ?? '',
      'checkaba' => $data['checkaba'] ?? '',
      'checkaccount' => $data['checkaccount'] ?? '',
      'account_holder_type' => $data['account_holder_type'] ?? 'personal',
      'account_type' => $data['account_type'] ?? 'checking',
      'first_name' => $data['first_name'],
      'last_name' => $data['last_name'],
      'address1' => $billingAddress ? $billingAddress->getStreet() : '',
      'city' => $billingAddress ? $billingAddress->getCity() : '',
      'state' => $billingAddress && $billingAddress->getCountryState() ? $billingAddress->getCountryState()->getShortCode() : '',
      'zip' => $billingAddress ? $billingAddress->getZipcode() : '',
      'country' => $billingAddress ? $billingAddress->getCountry()->getIso() : '',
      'phone' => $customer->getActiveBillingAddress()->getPhoneNumber() ?? '',
      'email' => $customer->getEmail(),
      'shipping_firstname' => $shippingAddress->getFirstName(),
      'shipping_lastname' => $shippingAddress->getLastName(),
      'shipping_address1' => $shippingAddress->getStreet() ? $shippingAddress->getStreet() : '',
      'shipping_city' => $shippingAddress->getCity() ? $shippingAddress->getCity() : '',
      'shipping_zip' => $shippingAddress->getZipcode() ? $shippingAddress->getZipcode() : '',
      'shipping_state' => $shippingAddress->getCountryState() ? $shippingAddress->getCountryState()->getShortCode() : '',
      'shipping_country' => $shippingAddress->getCountry() ? $shippingAddress->getCountry()->getIso() : '',
      'shipping_email' => $customer->getEmail(),
      'line_items' => $nonSubscriptionItems,
    ];

    $response = $this->nmiPaymentApiClient->createTransaction($postData);
    $this->logger->info('ACH Payment Response -> ' . json_encode($response));

    $processedResponse = $this->handleNMIResponse($response);

    if (!$processedResponse['success']) {
      return $processedResponse;
    }

    return [
      'success' => true,
      'message' => 'Transaction processed successfully',
      'transaction_id' => $processedResponse['transaction_id'],
    ];
  }

  public function handleNMIResponse(array $response): array
  {
    if (isset($response['response']) && $response['response'] === '1') {
      return [
        'success' => true,
        'message' => 'Payment successful!',
        'transaction_id' => $response['transactionid'],
      ];
    } else {
      return [
        'success' => false,
        'message' => 'Payment failed: ' . ($response['responsetext'] ?? 'Unknown error')
      ];
    }
  }
}

