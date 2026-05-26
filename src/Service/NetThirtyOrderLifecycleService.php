<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class NetThirtyOrderLifecycleService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function buildOrderCustomFields(
        OrderEntity $order,
        array $paymentLinkData,
        string $expirationDate,
        bool $preserveExistingLink = false
    ): array {
        $customFields = $order->getCustomFields() ?? [];
        $customFields[NetThirtyFields::DEALER_PAYMENT_TYPE] = NetThirtyFields::DEALER_PAYMENT_TYPE_NET30;

        $existingLink = (string) ($customFields[NetThirtyFields::PAYMENT_LINK] ?? '');
        $existingToken = (string) ($customFields[NetThirtyFields::PAYMENT_LINK_TOKEN] ?? '');
        $existingExpiration = (string) ($customFields[NetThirtyFields::PAYMENT_LINK_EXPIRES_AT] ?? '');

        $linkToUse = $paymentLinkData['payment_link_url'] ?? '';
        $tokenToUse = $paymentLinkData['token'] ?? '';
        $expirationToUse = $expirationDate;

        if ($preserveExistingLink && $existingLink !== '') {
            $linkToUse = $existingLink;
            $tokenToUse = $existingToken !== '' ? $existingToken : $tokenToUse;
            $expirationToUse = $existingExpiration !== '' ? $existingExpiration : $expirationToUse;
        }

        $customFields[NetThirtyFields::PAYMENT_LINK] = $linkToUse;
        $customFields[NetThirtyFields::PAYMENT_LINK_TOKEN] = $tokenToUse;
        $customFields[NetThirtyFields::PAYMENT_LINK_EXPIRES_AT] = $expirationToUse;
        $customFields[NetThirtyFields::INVOICE_NUMBER] = $order->getOrderNumber();

        return [$customFields, $linkToUse];
    }

    /**
     * @param array<string, mixed> $existingFields
     * @return array<string, mixed>
     */
    public function buildTransactionCustomFields(
        array $existingFields,
        string $orderNumber,
        array $paymentLinkData,
        string $expirationDate
    ): array {
        $existingFields[NetThirtyFields::PAYMENT_LINK] = $paymentLinkData['payment_link_url'] ?? '';
        $existingFields[NetThirtyFields::PAYMENT_LINK_TOKEN] = $paymentLinkData['token'] ?? '';
        $existingFields[NetThirtyFields::PAYMENT_LINK_EXPIRES_AT] = $expirationDate;
        $existingFields[NetThirtyFields::INVOICE_NUMBER] = $orderNumber;

        return $existingFields;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    public function updateOrderCustomFields(string $orderId, array $customFields, Context $context): void
    {
        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => $customFields,
        ]], $context);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    public function updateTransactionCustomFields(string $transactionId, array $customFields, Context $context): void
    {
        $this->orderTransactionRepository->update([[
            'id' => $transactionId,
            'customFields' => $customFields,
        ]], $context);
    }

    public function markPaymentLinkEmailSent(string $orderId, OrderEntity $order, Context $context): void
    {
        $customFields = $order->getCustomFields() ?? [];
        $customFields[NetThirtyFields::PAYMENT_LINK_EMAIL_SENT] = true;
        $this->updateOrderCustomFields($orderId, $customFields, $context);
    }

    public function hasPaymentLinkEmailSent(OrderEntity $order): bool
    {
        $customFields = $order->getCustomFields() ?? [];

        return (bool) ($customFields[NetThirtyFields::PAYMENT_LINK_EMAIL_SENT] ?? false);
    }
}
