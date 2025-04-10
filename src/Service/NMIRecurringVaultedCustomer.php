<?php

namespace NMIPayment\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class NMIRecurringVaultedCustomer
{
    public function __construct(private readonly NMIConfigService $nmiConfigService, private readonly NMIPaymentApiClient $nmiPaymentApiClient, private readonly VaultedCustomerService $vaultedCustomerService, private readonly OrderTransactionStateHandler $transactionStateHandler, private readonly EntityRepository $orderTransactionRepository, private readonly LoggerInterface $logger) {}

    public function recurringCapture(float $amount, string $customerId, string $orderId, Context $context): array
    {
        $this->logger->info('orderId', [$orderId]);
        $orderTransactionId = $this->getOrderTransactionIdByOrderId($orderId, $context);
        $this->logger->info('orderTransactionId', [$orderTransactionId]);

        $paymentMethodType = $this->nmiConfigService->getConfig('authorizeAndCapture');
        $customerVaultId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId);

        $postData = [
            'type' => $paymentMethodType ? 'auth' : 'sale',
            'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
            'amount' => (string) $amount,
            'customer_vault_id' => $customerVaultId,
        ];

        $response = $this->nmiPaymentApiClient->createTransaction($postData);
        $this->logger->info('response', [$response]);

        if ($this->handleNMIResponse($response)) {
            $this->transactionStateHandler->paid($orderTransactionId, $context);
        } else {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
        }

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
            ];
        }

        //            $this->logger->warning('Payment failed', ['response' => $response]);
        return [
            'success' => false,
            'message' => 'Payment failed: '.($response['responsetext'] ?? 'Unknown error'),
        ];
    }

    private function getOrderTransactionIdByOrderId(string $orderId, Context $context)
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        if ($orderTransaction) {
            return $orderTransaction->getId();
        }

        return null;
    }
}
