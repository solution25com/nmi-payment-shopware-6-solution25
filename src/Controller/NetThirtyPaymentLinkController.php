<?php

declare(strict_types=1);

namespace NMIPayment\Controller;

use NMIPayment\Service\NetThirtyPaymentLinkService;
use NMIPayment\Service\NetThirtyFields;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['storefront'], 'csrf_protected' => false])]
class NetThirtyPaymentLinkController extends StorefrontController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/dealer-payment-terms/payment-link/{orderId}',
        name: 'dealer_payment_terms.payment_link',
        methods: ['GET']
    )]
    public function paymentLink(string $orderId, Request $request, Context $context): Response
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order) {
            return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-error.html.twig', [
                'error' => 'Order not found.',
            ]);
        }

        $customFields = $order->getCustomFields() ?? [];
        $providedToken = (string) $request->query->get('token', '');
        $storedToken = (string) ($customFields[NetThirtyPaymentLinkService::getPaymentLinkTokenKey()] ?? '');

        if ($storedToken === '' || $providedToken === '' || !hash_equals($storedToken, $providedToken)) {
            return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-error.html.twig', [
                'error' => 'Invalid or missing payment link token.',
            ]);
        }

        if (isset($customFields[NetThirtyFields::PAYMENT_COMPLETED])) {
            return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-status.html.twig', [
                'order' => $order,
                'status' => 'paid',
                'message' => 'Your payment has been successfully processed.',
            ]);
        }

        if (isset($customFields[NetThirtyFields::PAYMENT_FAILED])) {
            return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-status.html.twig', [
                'order' => $order,
                'status' => 'failed',
                'message' => 'Payment was declined or failed. Please contact support.',
            ]);
        }

        $expiresAt = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;
        if ($expiresAt !== null) {
            try {
                $expirationDate = new \DateTime($expiresAt);
                $now = new \DateTime();
                if ($now > $expirationDate) {
                    return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-status.html.twig', [
                        'order' => $order,
                        'status' => 'expired',
                        'message' => 'This payment link has expired. Please contact support for a new link.',
                    ]);
                }
            } catch (\Exception $exception) {
                $this->logger->warning('Invalid expiration date on Net 30 payment link', [
                    'order_id' => $orderId,
                    'expiration_key' => $expiresAt,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->renderStorefront('@NMIPayment/storefront/page/payment-link-status.html.twig', [
            'order' => $order,
            'status' => 'info',
            'message' => 'Please use your account invoices page to pay this order with a saved card.',
        ]);
    }
}
