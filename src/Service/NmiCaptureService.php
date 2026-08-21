<?php declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Gateways\CreditCard;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class NmiCaptureService
{
    public const RESULT_CAPTURED = 'captured';
    public const RESULT_VOIDED_ZERO = 'voided_zero';
    public const RESULT_DEFERRED = 'deferred';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_FAILED = 'failed';

    private const CAPTURE_FIELD = 'NmiPaymentAmountCapture';
    private const CAPTURE_SHORTFALL_FIELD = 'nmiCaptureShortfall';
    private const CAPTURE_BLOCKED_FIELD = 'nmiCaptureBlocked';
    private const EXCEEDS_AUTH_TEXT = 'exceeds the authorization amount';

    public function __construct(
        private readonly NmiTransactionService $transactionService,
        private readonly NMIPaymentApiClient $apiClient,
        private readonly NMIConfigService $configService,
        private readonly EntityRepository $orderRepository,
        private readonly ?EntityRepository $partialDeliveryRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function captureForOrder(string $orderId, Context $context): string
    {
        $order = $this->loadOrder($orderId, $context);
        if (!$order instanceof OrderEntity) {
            return self::RESULT_SKIPPED;
        }

        $orderTransaction = $order->getTransactions()?->last();
        if ($orderTransaction?->getPaymentMethod()?->getHandlerIdentifier() !== CreditCard::class) {
            return self::RESULT_SKIPPED;
        }

        $nmiTransaction = $this->transactionService->getTransactionByOrderId($orderId, $context);
        if ($nmiTransaction === null || trim((string) $nmiTransaction->getTransactionId()) === '') {
            $this->logger->error('[capture] missing NMI transaction', ['orderId' => $orderId]);
            return self::RESULT_SKIPPED;
        }

        $customFields = $order->getCustomFields() ?? [];
        if (array_key_exists(self::CAPTURE_FIELD, $customFields)) {
            return self::RESULT_SKIPPED;
        }

        if (!empty($customFields[self::CAPTURE_BLOCKED_FIELD])) {
            return self::RESULT_SKIPPED;
        }

        $captureAmount = $this->calculateCaptureAmount($order, $context);
        if ($captureAmount === null) {
            $this->logger->warning('[capture] deferred; partial delivery rows are not ready', [
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
            ]);
            return self::RESULT_DEFERRED;
        }

        $authorizedAmount = $nmiTransaction->getAuthAmount();
        $shortfall = 0.0;
        if ($authorizedAmount !== null && $authorizedAmount > 0.0 && $captureAmount > $authorizedAmount) {
            $shortfall = round($captureAmount - $authorizedAmount, 2);
            $captureAmount = round($authorizedAmount, 2);
        }

        $this->apiClient->initializeForSalesChannel($order->getSalesChannelId());

        if ($captureAmount <= 0.0) {
            $response = $this->apiClient->createTransaction([
                'security_key' => $this->securityKey($order->getSalesChannelId()),
                'type' => 'void',
                'transactionid' => $nmiTransaction->getTransactionId(),
                'void_reason' => 'zero shipped amount',
            ]);

            if (!$this->isSuccessful($response)) {
                return self::RESULT_FAILED;
            }

            $this->markCaptured($order, 0.0, 0.0, $context);
            return self::RESULT_VOIDED_ZERO;
        }

        $response = $this->capture($order, $nmiTransaction->getTransactionId(), $captureAmount);

        if (!$this->isSuccessful($response)
            && $authorizedAmount === null
            && $this->isExceedsAuthorizationDecline($response)) {
            $verified = $this->apiClient->queryTransaction($nmiTransaction->getTransactionId());
            $gatewayAmount = is_numeric($verified['amount'] ?? null) ? (float) $verified['amount'] : null;

            if ($gatewayAmount !== null && $gatewayAmount > 0.0 && $gatewayAmount < $captureAmount) {
                $shortfall = round($captureAmount - $gatewayAmount, 2);
                $captureAmount = round($gatewayAmount, 2);
                $response = $this->capture($order, $nmiTransaction->getTransactionId(), $captureAmount);
            }
        }

        if (!$this->isSuccessful($response)) {
            if ($this->isExceedsAuthorizationDecline($response)) {
                $customFields[self::CAPTURE_BLOCKED_FIELD] = $response['responsetext'] ?? 'authorization exceeded';
                $this->orderRepository->update([[
                    'id' => $order->getId(),
                    'customFields' => $customFields,
                ]], $context);
            }

            $this->logger->error('[capture] NMI capture failed', [
                'orderId' => $orderId,
                'responseCode' => $response['response'] ?? null,
                'responseText' => $response['responsetext'] ?? null,
            ]);
            return self::RESULT_FAILED;
        }

        $this->markCaptured($order, $captureAmount, $shortfall, $context);
        $this->transactionService->updateTransactionStatus($orderId, 'paid', $context);

        return self::RESULT_CAPTURED;
    }

    private function loadOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('deliveries.positions');

        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function calculateCaptureAmount(OrderEntity $order, Context $context): ?float
    {
        if ($this->partialDeliveryRepository === null) {
            return round($order->getAmountTotal(), 2);
        }

        $quantities = $this->shipmentQuantities($order->getId(), $context);
        if ($quantities === null) {
            return null;
        }

        $amount = 0.0;
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            $ordered = max(1, $lineItem->getQuantity());
            $shipped = min($ordered, $quantities[$lineItem->getId()] ?? 0);
            $amount += ($lineItem->getPrice()?->getTotalPrice() ?? 0.0) / $ordered * $shipped;
        }

        if ($amount > 0.0) {
            $amount += $order->getShippingCosts()->getTotalPrice();
        }

        $amount = round($amount, 2);
        if (abs($order->getAmountTotal() - $amount) <= 0.3) {
            return round($order->getAmountTotal(), 2);
        }

        return $amount;
    }

    private function shipmentQuantities(string $orderId, Context $context): ?array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addAssociation('positions');
        $deliveries = $this->orderDeliveryRepository->search($criteria, $context)->getEntities();
        $quantities = [];
        $foundShipment = false;

        /** @var OrderDeliveryEntity $delivery */
        foreach ($deliveries as $delivery) {
            /** @var OrderDeliveryPositionEntity $position */
            foreach ($delivery->getPositions() ?? [] as $position) {
                $lineItemId = $position->getOrderLineItemId();
                $shipmentCriteria = (new Criteria())
                    ->addFilter(new EqualsFilter('orderLineItemId', $lineItemId));
                $shipments = $this->partialDeliveryRepository->search($shipmentCriteria, $context)->getEntities();

                foreach ($shipments as $shipment) {
                    $foundShipment = true;
                    $quantities[$lineItemId] = ($quantities[$lineItemId] ?? 0) + (int) $shipment->getQuantity();
                }
            }
        }

        return $foundShipment ? $quantities : null;
    }

    private function capture(OrderEntity $order, string $transactionId, float $amount): ?array
    {
        return $this->apiClient->createTransaction([
            'security_key' => $this->securityKey($order->getSalesChannelId()),
            'type' => 'capture',
            'transactionid' => $transactionId,
            'amount' => $amount,
            'orderid' => $order->getOrderNumber(),
        ]);
    }

    private function markCaptured(OrderEntity $order, float $amount, float $shortfall, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $customFields[self::CAPTURE_FIELD] = $amount;
        if ($shortfall > 0.0) {
            $customFields[self::CAPTURE_SHORTFALL_FIELD] = $shortfall;
        }

        $this->orderRepository->update([[
            'id' => $order->getId(),
            'customFields' => $customFields,
        ]], $context);
    }

    private function securityKey(string $salesChannelId): string
    {
        $mode = $this->configService->getConfig('mode', $salesChannelId);

        return (string) $this->configService->getConfig(
            $mode === 'live' ? 'privateKeyApiLive' : 'privateKeyApi',
            $salesChannelId
        );
    }

    private function isSuccessful(?array $response): bool
    {
        return ($response['response'] ?? null) === '1';
    }

    private function isExceedsAuthorizationDecline(?array $response): bool
    {
        return stripos((string) ($response['responsetext'] ?? ''), self::EXCEEDS_AUTH_TEXT) !== false;
    }
}
