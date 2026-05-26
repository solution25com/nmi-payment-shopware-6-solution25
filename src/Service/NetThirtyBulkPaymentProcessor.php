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
use Psr\Log\LoggerInterface;

class NetThirtyBulkPaymentProcessor
{
    public function __construct(
        private readonly NMIPaymentApiClient $nmiApiClient,
        private readonly NMIConfigService $nmiConfigService,
        private readonly NMIPaymentDataRequestService $nmiDataRequestService,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processBulkPaymentWithVaultedCard(
        string $customerVaultId,
        string $billingId,
        array $orderIds,
        Context $context
    ): array {
        $results = [];

        foreach ($orderIds as $orderId) {
            try {
                $results[] = $this->processSingleOrder($orderId, $customerVaultId, $billingId, $context);
            } catch (\Throwable $e) {
                $this->logger->error('[NET30][NMI] Bulk payment failed for order', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $results[] = [
                    'order_id' => $orderId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function processSingleOrder(
        string $orderId,
        string $customerVaultId,
        string $billingId,
        Context $context
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
            ->addAssociation('deliveries.shippingOrderAddress.countryState');

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
        $securityKey = $this->getSecurityKey($salesChannelId);
        $this->nmiApiClient->initializeForSalesChannel($salesChannelId);

        $amount = $order->getPrice()->getTotalPrice();
        $authorizeOnly = (bool) $this->nmiConfigService->getConfig('authorizeAndCapture', $salesChannelId);

        $billing  = $order->getBillingAddress();
        $shipping = $order->getDeliveries()?->first()?->getShippingOrderAddress();

        $payload = [
            'security_key'       => $securityKey,
            'amount'             => $amount,
            'currency'           => $order->getCurrency()?->getIsoCode() ?? 'USD',
            'type'               => $authorizeOnly ? 'auth' : 'sale',
            'orderid'            => $order->getOrderNumber(),
            'customer_vault_id'  => $customerVaultId,
            'billing_id'         => $billingId,
            'dup_seconds'        => 0,
            // billing address
            'first_name'         => $billing?->getFirstName() ?? '',
            'last_name'          => $billing?->getLastName() ?? '',
            'address1'           => $billing?->getStreet() ?? '',
            'city'               => $billing?->getCity() ?? '',
            'state'              => $billing?->getCountryState()?->getShortCode() ?? '',
            'zip'                => $billing?->getZipcode() ?? '',
            'country'            => $billing?->getCountry()?->getIso() ?? '',
            'phone'              => $billing?->getPhoneNumber() ?? '',
            'email'              => $order->getOrderCustomer()?->getEmail() ?? '',
            // shipping address
            'shipping_firstname' => $shipping?->getFirstName() ?? ($billing?->getFirstName() ?? ''),
            'shipping_lastname'  => $shipping?->getLastName()  ?? ($billing?->getLastName()  ?? ''),
            'shipping_address1'  => $shipping?->getStreet()    ?? ($billing?->getStreet()    ?? ''),
            'shipping_city'      => $shipping?->getCity()      ?? ($billing?->getCity()      ?? ''),
            'shipping_zip'       => $shipping?->getZipcode()   ?? ($billing?->getZipcode()   ?? ''),
            'shipping_state'     => $shipping?->getCountryState()?->getShortCode() ?? ($billing?->getCountryState()?->getShortCode() ?? ''),
            'shipping_country'   => $shipping?->getCountry()?->getIso()            ?? ($billing?->getCountry()?->getIso()            ?? ''),
            'shipping_email'     => $order->getOrderCustomer()?->getEmail() ?? '',
        ];

        $response = $this->nmiApiClient->createTransaction($payload);
        $processed = $this->nmiDataRequestService->handleNMIResponse($response ?? []);

        if (!($processed['success'] ?? false)) {
            $message = $processed['message'] ?? 'Payment failed';
            throw new \RuntimeException($message);
        }

        $transactionId = $processed['transaction_id'] ?? null;

        $this->markOrderPaidOrAuthorized($order, $transaction, $transactionId, $authorizeOnly, $context);

        return [
            'order_id' => $orderId,
            'order_number' => $order->getOrderNumber(),
            'success' => true,
            'transaction_id' => $transactionId,
        ];
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

        $order->setCustomFields($customFields);

        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => $customFields,
        ]], $context);
    }
}
