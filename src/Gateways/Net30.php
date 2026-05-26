<?php

declare(strict_types=1);

namespace NMIPayment\Gateways;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyOrderLifecycleService;
use NMIPayment\Service\NetThirtyPaymentOrchestrator;
use NMIPayment\Service\NetThirtyPaymentLinkService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class Net30 extends AbstractPaymentHandler
{
    public const TECHNICAL_NAME = 'dealer_net_thirty';
    public const ORDER_CUSTOM_FIELD_KEY = NetThirtyFields::DEALER_PAYMENT_TYPE;
    public const ORDER_CUSTOM_FIELD_VALUE = NetThirtyFields::DEALER_PAYMENT_TYPE_NET30;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly NetThirtyPaymentLinkService $paymentLinkService,
        private readonly NetThirtyOrderLifecycleService $orderLifecycleService,
        private readonly NetThirtyPaymentOrchestrator $paymentOrchestrator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $this->logger->info('[NET30] pay() entered', [
            'order_transaction_id' => $transaction->getOrderTransactionId(),
        ]);

        $orderTransaction = $this->getOrderTransaction($transaction, $context);
        $order = $orderTransaction->getOrder();
        $orderId = $order?->getId();

        if ($order === null && $request->request->has('orderId')) {
            $orderIdFromRequest = (string) $request->request->get('orderId');
            if ($orderIdFromRequest !== '') {
                $criteria = new Criteria([$orderIdFromRequest]);
                $criteria->addAssociation('lineItems');
                $criteria->addAssociation('billingAddress');
                $criteria->addAssociation('billingAddress.country');
                $criteria->addAssociation('billingAddress.countryState');
                $criteria->addAssociation('deliveries.shippingOrderAddress');
                $criteria->addAssociation('deliveries.shippingOrderAddress.country');
                $criteria->addAssociation('deliveries.shippingOrderAddress.countryState');
                $criteria->addAssociation('orderCustomer');
                $criteria->addAssociation('currency');
                $criteria->addAssociation('salesChannel');
                $criteria->addAssociation('price');
                $order = $this->orderRepository->search($criteria, $context)->first();
                $orderId = $order?->getId();
            }
        }

        if ($order === null || $orderId === null) {
            $this->logger->error('[NET30] pay() missing order context', [
                'order_transaction_id' => $transaction->getOrderTransactionId(),
            ]);
            throw PaymentException::invalidOrder('Order or order ID not found!');
        }

        try {
            $expirationDate = (new \DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
            $paymentLinkData = $this->paymentLinkService->createPaymentLink($order, $context, 30);

            [$customFields, $paymentLinkUrl] = $this->orderLifecycleService->buildOrderCustomFields(
                $order,
                $paymentLinkData,
                $expirationDate
            );
            $transactionCustomFields = $this->orderLifecycleService->buildTransactionCustomFields(
                $orderTransaction->getCustomFields() ?? [],
                (string) $order->getOrderNumber(),
                $paymentLinkData,
                $expirationDate
            );

            $this->orderLifecycleService->updateOrderCustomFields($orderId, $customFields, $context);
            $this->orderLifecycleService->updateTransactionCustomFields($orderTransaction->getId(), $transactionCustomFields, $context);

            $this->logger->info('Net 30 payment link created for order: ' . $order->getOrderNumber(), [
                'order_id' => $orderId,
                'expiration_date' => $expirationDate,
            ]);

            $order = $this->reloadOrderForEmail($orderId, $context);
            if ($order === null) {
                $this->logger->error('Order not found after update when trying to send Net 30 email', [
                    'order_id' => $orderId,
                ]);
            } else {
                try {
                    $this->sendNet30Email($order, $orderId, $paymentLinkUrl, $expirationDate, $context);
                } catch (\Exception $emailException) {
                    $this->logger->error('Failed to send Net 30 payment notification email: ' . $emailException->getMessage(), [
                        'order_id' => $orderId,
                        'error' => $emailException->getTraceAsString(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to create Net 30 payment link: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'error' => $e->getTraceAsString(),
            ]);
            throw PaymentException::syncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Failed to create payment link: ' . $e->getMessage()
            );
        }

        $currentState = $orderTransaction->getStateMachineState()?->getTechnicalName();
        if ($currentState !== OrderStateInstaller::STATE_TECHNICAL_NAME) {
            try {
                $this->transitionToNetThirty($orderTransaction->getId(), $currentState, $context);
            } catch (IllegalTransitionException $exception) {
                throw PaymentException::invalidTransition($transaction->getOrderTransactionId(), $exception->getMessage());
            }
        }

        return null;
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return $type->equals(PaymentHandlerType::SYNC);
    }

    private function getOrderTransaction(PaymentTransactionStruct $transaction, Context $context): OrderTransactionEntity
    {
        $criteria = (new Criteria([$transaction->getOrderTransactionId()]))
            ->addAssociation('order')
            ->addAssociation('order.orderCustomer')
            ->addAssociation('order.currency')
            ->addAssociation('order.salesChannel')
            ->addAssociation('order.price');

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($orderTransaction === null) {
            throw PaymentException::orderTransactionNotFound($transaction->getOrderTransactionId());
        }

        return $orderTransaction;
    }

    private function reloadOrderForEmail(string $orderId, Context $context): ?OrderEntity
    {
        return $this->orderRepository->search(
            (new Criteria([$orderId]))->addAssociation('orderCustomer')->addAssociation('currency')->addAssociation('price'),
            $context
        )->first();
    }

    private function sendNet30Email(
        OrderEntity $order,
        string $orderId,
        string $paymentLinkUrl,
        string $expirationDate,
        Context $context
    ): void {
        $emailSent = $this->paymentOrchestrator->sendPaymentLinkEmailIfNeeded(
            $order,
            $paymentLinkUrl,
            $expirationDate,
            $context
        );

        if ($emailSent) {
            $this->logger->info('Net 30 payment notification email sent successfully', [
                'order_id' => $orderId,
                'order_number' => $order->getOrderNumber(),
            ]);
        } else {
            $this->logger->info('Net 30 payment notification email already sent; skipping duplicate', [
                'order_id' => $orderId,
                'order_number' => $order->getOrderNumber(),
            ]);
        }
    }

    private function transitionToNetThirty(string $transactionId, ?string $currentState, Context $context): void
    {
        $this->paymentOrchestrator->transitionToNetThirtyIfNeeded(
            $transactionId,
            $currentState,
            $context
        );
    }
}
