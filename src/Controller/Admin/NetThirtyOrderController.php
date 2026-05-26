<?php

declare(strict_types=1);

namespace NMIPayment\Controller\Admin;

use NMIPayment\Service\NetThirtyEmailService;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['order.editor']])]
class NetThirtyOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly NetThirtyPaymentLinkService $paymentLinkService,
        private readonly NetThirtyEmailService $emailService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/api/_action/dealer-payment-terms/resend-payment-link/{orderId}',
        name: 'api.dealer_payment_terms.resend_payment_link',
        methods: ['POST']
    )]
    public function resendPaymentLink(string $orderId, Request $request, Context $context): JsonResponse
    {
        try {
            $order = $this->getOrder($orderId, $context);
            if ($order === null) {
                return $this->errorResponse('Order not found', Response::HTTP_NOT_FOUND);
            }

            $customFields = $order->getCustomFields() ?? [];
            if (!$this->isNet30Order($customFields)) {
                return $this->errorResponse('This order is not a Net 30 payment order', Response::HTTP_BAD_REQUEST);
            }

            if (isset($customFields[NetThirtyFields::PAYMENT_COMPLETED])) {
                return $this->errorResponse('This order has already been paid', Response::HTTP_BAD_REQUEST);
            }

            $alternateDueDate = $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] ?? null;
            $existingExpiration = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;

            $paymentLinkData = $this->paymentLinkService->createPaymentLink($order, $context, null);

            $customFields[NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()] = $paymentLinkData['payment_link_url'];
            $customFields[NetThirtyPaymentLinkService::getPaymentLinkTokenKey()] = $paymentLinkData['token'];
            $customFields[NetThirtyFields::PAYMENT_LINK_RESENT_AT] = (new \DateTime())->format('Y-m-d H:i:s');
            $customFields[NetThirtyFields::PAYMENT_LINK_RESEND_COUNT] = ($customFields[NetThirtyFields::PAYMENT_LINK_RESEND_COUNT] ?? 0) + 1;

            $this->updateOrderCustomFields($orderId, $customFields, $context);

            $orderTransaction = $order->getTransactions()?->first();
            if ($orderTransaction) {
                $transactionCustomFields = $orderTransaction->getCustomFields() ?? [];
                $transactionCustomFields[NetThirtyFields::PAYMENT_LINK] = $paymentLinkData['payment_link_url'];
                $transactionCustomFields[NetThirtyFields::PAYMENT_LINK_TOKEN] = $paymentLinkData['token'];
                if ($existingExpiration !== null) {
                    $transactionCustomFields[NetThirtyFields::PAYMENT_LINK_EXPIRES_AT] = $existingExpiration;
                }

                $this->orderTransactionRepository->update([[
                    'id' => $orderTransaction->getId(),
                    'customFields' => $transactionCustomFields,
                ]], $context);
            }

            try {
                $this->emailService->sendPaymentLinkEmail(
                    $order,
                    $paymentLinkData['payment_link_url'],
                    $existingExpiration ?? $paymentLinkData['expires_at'],
                    $context
                );
            } catch (\Exception $emailException) {
                $this->logger->error('Failed to send resend payment link email', [
                    'order_id' => $orderId,
                    'error' => $emailException->getMessage(),
                ]);
            }

            $this->logger->info('Payment link resent for Net 30 order', [
                'order_id' => $orderId,
                'order_number' => $order->getOrderNumber(),
                'resend_count' => $customFields[NetThirtyFields::PAYMENT_LINK_RESEND_COUNT],
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Payment link has been resent successfully',
                'paymentLink' => $paymentLinkData['payment_link_url'],
                'expiresAt' => $existingExpiration ?? $paymentLinkData['expires_at'],
                'alternateDueDate' => $alternateDueDate,
                'resendCount' => $customFields[NetThirtyFields::PAYMENT_LINK_RESEND_COUNT],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to resend payment link', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to resend payment link: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/api/_action/dealer-payment-terms/set-alternate-due-date/{orderId}',
        name: 'api.dealer_payment_terms.set_alternate_due_date',
        methods: ['POST']
    )]
    public function setAlternateDueDate(string $orderId, Request $request, Context $context): JsonResponse
    {
        try {
            $order = $this->getOrder($orderId, $context);
            if ($order === null) {
                return $this->errorResponse('Order not found', Response::HTTP_NOT_FOUND);
            }

            $customFields = $order->getCustomFields() ?? [];
            if (!$this->isNet30Order($customFields)) {
                return $this->errorResponse('This order is not a Net 30 payment order', Response::HTTP_BAD_REQUEST);
            }

            $expirationStr = $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null;
            $baseDate = $this->resolveBaseDate($order, $expirationStr);

            $date = (clone $baseDate)->modify('+30 days');
            $alternateDueDate = $date->format('Y-m-d');
            $dateWithTime = (clone $date)->setTime(23, 59, 59);

            $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] = $alternateDueDate;
            $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] = $dateWithTime->format('Y-m-d H:i:s');

            $orderTransaction = $order->getTransactions()?->first();
            if ($orderTransaction) {
                $transactionCustomFields = $orderTransaction->getCustomFields() ?? [];
                $transactionCustomFields[NetThirtyFields::PAYMENT_LINK_EXPIRES_AT] = $dateWithTime->format('Y-m-d H:i:s');

                $this->orderTransactionRepository->update([[
                    'id' => $orderTransaction->getId(),
                    'customFields' => $transactionCustomFields,
                ]], $context);
            }

            $this->updateOrderCustomFields($orderId, $customFields, $context);

            $this->logger->info('Alternate due date set for Net 30 order', [
                'order_id' => $orderId,
                'order_number' => $order->getOrderNumber(),
                'alternate_due_date' => $alternateDueDate,
            ]);

            return $this->successResponse([
                'success' => true,
                'message' => 'Alternate due date has been set successfully',
                'alternateDueDate' => $alternateDueDate,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to set alternate due date', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to set alternate due date: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/api/_action/dealer-payment-terms/order-details/{orderId}',
        name: 'api.dealer_payment_terms.order_details',
        methods: ['GET']
    )]
    public function getOrderDetails(string $orderId, Context $context): JsonResponse
    {
        try {
            $order = $this->getOrder($orderId, $context);
            if ($order === null) {
                return $this->errorResponse('Order not found', Response::HTTP_NOT_FOUND);
            }

            $customFields = $order->getCustomFields() ?? [];
            $isNet30 = isset($customFields[NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()]);

            if (!$isNet30) {
                return $this->errorResponse('This order is not a Net 30 payment order', Response::HTTP_BAD_REQUEST);
            }

            $orderTransaction = $order->getTransactions()?->first();
            $currentState = $orderTransaction?->getStateMachineState()?->getTechnicalName();

            return $this->successResponse([
                'success' => true,
                'order' => [
                    'id' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'paymentLink' => $customFields[NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()] ?? null,
                    'expiresAt' => $customFields[NetThirtyPaymentLinkService::getPaymentLinkExpirationKey()] ?? null,
                    'alternateDueDate' => $customFields[NetThirtyFields::ALTERNATE_DUE_DATE] ?? null,
                    'paymentCompleted' => $customFields[NetThirtyFields::PAYMENT_COMPLETED] ?? false,
                    'paymentFailed' => $customFields[NetThirtyFields::PAYMENT_FAILED] ?? false,
                    'paymentOverdue' => $customFields[NetThirtyFields::PAYMENT_OVERDUE] ?? false,
                    'resendCount' => $customFields[NetThirtyFields::PAYMENT_LINK_RESEND_COUNT] ?? 0,
                    'lastResentAt' => $customFields[NetThirtyFields::PAYMENT_LINK_RESENT_AT] ?? null,
                    'currentState' => $currentState,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get Net 30 order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to get order details: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function getOrder(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('price');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('billingAddress');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('billingAddress.countryState');
        $criteria->addAssociation('deliveries.shippingOrderAddress');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    private function isNet30Order(array $customFields): bool
    {
        return isset($customFields[NetThirtyPaymentLinkService::getPaymentLinkCustomFieldKey()]);
    }

    private function resolveBaseDate(OrderEntity $order, ?string $expirationStr): \DateTime
    {
        if ($expirationStr !== null) {
            try {
                return new \DateTime($expirationStr);
            } catch (\Exception $e) {
            }
        }

        return $order->getOrderDateTime() ?? new \DateTime();
    }

    private function updateOrderCustomFields(string $orderId, array $customFields, Context $context): void
    {
        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => $customFields,
        ]], $context);
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function successResponse(array $payload): JsonResponse
    {
        return new JsonResponse($payload);
    }
}
