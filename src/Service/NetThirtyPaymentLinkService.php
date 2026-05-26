<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NetThirtyPaymentLinkService
{
    private const PAYMENT_LINK_EXPIRATION_HOURS = 2;

    public function __construct(
        private readonly NMIPaymentApiClient $nmiApiClient,
        private readonly NMIConfigService $nmiConfigService,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger
    ) {
    }

    public function createPaymentLink(OrderEntity $order, Context $context, ?int $expirationDays = null): array
    {
        try {
            $expirationHours = $expirationDays !== null ? ($expirationDays * 24) : self::PAYMENT_LINK_EXPIRATION_HOURS;
            $expirationDate = (new \DateTime())->modify('+' . $expirationHours . ' hours');
            $token = bin2hex(random_bytes(16));

            $paymentLinkUrl = $this->router->generate(
                'dealer_payment_terms.payment_link',
                [
                    'orderId' => $order->getId(),
                    'token' => $token,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $this->logger->info('Created Net 30 payment link for order: ' . $order->getOrderNumber(), [
                'order_id' => $order->getId(),
                'expires_at' => $expirationDate->format('Y-m-d H:i:s'),
            ]);

            return [
                'token' => $token,
                'payment_link_url' => $paymentLinkUrl,
                'expires_at' => $expirationDate->format('Y-m-d H:i:s'),
                'expires_at_timestamp' => $expirationDate->getTimestamp(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error creating Net 30 payment link: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public static function getPaymentLinkCustomFieldKey(): string
    {
        return NetThirtyFields::PAYMENT_LINK;
    }

    public static function getPaymentLinkExpirationKey(): string
    {
        return NetThirtyFields::PAYMENT_LINK_EXPIRES_AT;
    }

    public static function getPaymentLinkTokenKey(): string
    {
        return NetThirtyFields::PAYMENT_LINK_TOKEN;
    }

    public static function getPaymentLinkInvoiceNumberKey(): string
    {
        return NetThirtyFields::INVOICE_NUMBER;
    }
}
