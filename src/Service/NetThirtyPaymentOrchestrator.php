<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Installer\OrderStateInstaller;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Psr\Log\LoggerInterface;

class NetThirtyPaymentOrchestrator
{
    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly NetThirtyEmailService $emailService,
        private readonly NetThirtyOrderLifecycleService $orderLifecycleService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function transitionToNetThirtyIfNeeded(string $transactionId, ?string $currentState, Context $context): void
    {
        if ($currentState === OrderStateInstaller::STATE_TECHNICAL_NAME) {
            return;
        }

        $this->stateMachineRegistry->transition(
            new Transition(
                'order_transaction',
                $transactionId,
                OrderStateInstaller::TRANSITION_ACTION_NAME,
                'stateId'
            ),
            $context
        );
    }

    public function sendPaymentLinkEmailIfNeeded(
        OrderEntity $order,
        string $paymentLinkUrl,
        string $expirationDate,
        Context $context
    ): bool {
        if ($this->orderLifecycleService->hasPaymentLinkEmailSent($order)) {
            return false;
        }

        $orderId = (string) $order->getId();
        $this->emailService->sendPaymentLinkEmail(
            $order,
            $paymentLinkUrl,
            $expirationDate,
            $context
        );

        $this->orderLifecycleService->markPaymentLinkEmailSent($orderId, $order, $context);
        $this->logger->info('[NET30] Payment link email marked as sent', [
            'order_id' => $orderId,
            'order_number' => $order->getOrderNumber(),
        ]);

        return true;
    }
}
