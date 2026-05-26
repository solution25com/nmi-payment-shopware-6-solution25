<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;

class NetThirtyEmailService
{
    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $salesChannelRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sendPaymentLinkEmail(
        OrderEntity $order,
        string $paymentLinkUrl,
        string $expirationDate,
        Context $context
    ): void {
        $this->logger->info('Attempting to send Net 30 payment notification email', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
        ]);

        try {
            $orderCustomer = $order->getOrderCustomer();
            if ($orderCustomer === null) {
                $this->logger->warning('Cannot send Net 30 payment notification email: Order customer not found', [
                    'order_id' => $order->getId(),
                ]);
                return;
            }

            $customerEmail = $orderCustomer->getEmail();
            $customerName = trim(($orderCustomer->getFirstName() ?? '') . ' ' . ($orderCustomer->getLastName() ?? ''));

            if (empty($customerEmail)) {
                $this->logger->warning('Cannot send Net 30 payment notification email: Customer email is empty', [
                    'order_id' => $order->getId(),
                ]);
                return;
            }

            $salesChannel = $this->getSalesChannel($order->getSalesChannelId(), $context);
            if ($salesChannel === null) {
                $this->logger->error('Cannot send Net 30 payment notification email: Sales channel not found', [
                    'order_id' => $order->getId(),
                    'sales_channel_id' => $order->getSalesChannelId(),
                ]);
                return;
            }

            $salesChannelDomain = $salesChannel->getDomains()?->first();
            $baseUrl = $salesChannelDomain?->getUrl() ?? '';

            $invoicesPageUrl = $this->router->generate(
                'frontend.account.invoices.page',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            if ($baseUrl && strpos($invoicesPageUrl, 'http') !== 0) {
                $invoicesPageUrl = rtrim($baseUrl, '/') . '/account/invoices';
            }

            $salesChannelName = $salesChannel->getName() ?? 'Store';
            $orderNumber = $order->getOrderNumber();
            $orderTotal = $order->getPrice()->getTotalPrice();
            $currency = $order->getCurrency()?->getIsoCode() ?? 'USD';

            $senderEmail = $this->systemConfigService->get(
                'core.basicInformation.email',
                $order->getSalesChannelId()
            ) ?? $this->systemConfigService->get('core.basicInformation.email') ?? 'noreply@example.com';

            $subject = sprintf('Payment Due for Order #%s - Net 30 Terms', $orderNumber);
            $htmlContent = $this->generateEmailHtml(
                $customerName ?: 'Customer',
                $orderNumber,
                $invoicesPageUrl,
                $expirationDate,
                $orderTotal,
                $currency
            );
            $plainContent = $this->generateEmailPlain(
                $customerName ?: 'Customer',
                $orderNumber,
                $invoicesPageUrl,
                $expirationDate,
                $orderTotal,
                $currency
            );

            $data = new DataBag();
            $data->set('recipients', [$customerEmail => $customerName ?: 'Customer']);
            $data->set('senderName', $salesChannelName);
            $data->set('senderEmail', $senderEmail);
            $data->set('subject', $subject);
            $data->set('contentHtml', $htmlContent);
            $data->set('contentPlain', $plainContent);
            $data->set('salesChannelId', $order->getSalesChannelId());

            $this->mailService->send($data->all(), $context, [
                'order' => $order,
                'paymentLinkUrl' => $invoicesPageUrl,
                'expirationDate' => $expirationDate,
            ]);

            $this->logger->info('Net 30 payment link email sent successfully', [
                'order_id' => $order->getId(),
                'order_number' => $orderNumber,
                'customer_email' => $customerEmail,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send Net 30 payment link email: ' . $e->getMessage(), [
                'order_id' => $order->getId(),
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    private function generateEmailHtml(
        string $customerName,
        string $orderNumber,
        string $invoicesPageUrl,
        string $expirationDate,
        float $orderTotal,
        string $currency
    ): string {
        $formattedTotal = number_format($orderTotal, 2);
        $expirationDateFormatted = date('F j, Y', strtotime($expirationDate));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Link for Order #{$orderNumber}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { background-color: #ffffff; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px; }
        .payment-link-box { background-color: #f0f7ff; border: 2px solid #0066cc; border-radius: 5px; padding: 20px; margin: 20px 0; text-align: center; }
        .payment-button { display: inline-block; background-color: #0066cc; color: #ffffff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; margin: 10px 0; }
        .info-box { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .order-details { margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-radius: 5px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header"><h1>Payment Due for Your Order</h1></div>
    <div class="content">
        <p>Dear {$customerName},</p>
        <p>Thank you for your order <strong>#{$orderNumber}</strong>. Your order has been placed with <strong>Net 30 payment terms</strong>.</p>
        <div class="order-details">
            <p><strong>Order Number:</strong> #{$orderNumber}</p>
            <p><strong>Order Total:</strong> {$currency} {$formattedTotal}</p>
            <p><strong>Payment Due Date:</strong> {$expirationDateFormatted}</p>
        </div>
        <div class="payment-link-box">
            <h2>Pay Your Invoice</h2>
            <p>Click the button below to view your invoices and complete your payment:</p>
            <a href="{$invoicesPageUrl}" class="payment-button">View Invoices &amp; Pay</a>
        </div>
        <div class="info-box">
            <p><strong>Important:</strong> Payment is due on <strong>{$expirationDateFormatted}</strong>.</p>
        </div>
        <p>Thank you for your business!</p>
    </div>
    <div class="footer">
        <p>This is an automated email. Please do not reply to this message.</p>
    </div>
</body>
</html>
HTML;
    }

    private function generateEmailPlain(
        string $customerName,
        string $orderNumber,
        string $invoicesPageUrl,
        string $expirationDate,
        float $orderTotal,
        string $currency
    ): string {
        $formattedTotal = number_format($orderTotal, 2);
        $expirationDateFormatted = date('F j, Y', strtotime($expirationDate));

        return <<<TEXT
Payment Due for Your Order

Dear {$customerName},

Thank you for your order #{$orderNumber}. Your order has been placed with Net 30 payment terms.

Order Details:
- Order Number: #{$orderNumber}
- Order Total: {$currency} {$formattedTotal}
- Payment Due Date: {$expirationDateFormatted}

Pay Your Invoice:
{$invoicesPageUrl}

IMPORTANT: Payment is due on {$expirationDateFormatted}.

Thank you for your business!

---
This is an automated email. Please do not reply to this message.
TEXT;
    }

    private function getSalesChannel(?string $salesChannelId, Context $context): ?SalesChannelEntity
    {
        if ($salesChannelId === null) {
            return null;
        }

        $criteria = new Criteria([$salesChannelId]);
        return $this->salesChannelRepository->search($criteria, $context)->first();
    }
}
