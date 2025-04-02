<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RefundEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly NmiTransactionService $nmiTransactionService, private readonly NMIPaymentApiClient $nmiPaymentApiClient, private readonly EntityRepository $orderReturnRepository, private readonly EntityRepository $orderTransactionRepository, private readonly OrderTransactionStateHandler $transactionStateHandler, private readonly NMIConfigService $nmiConfigService) {}

    public static function getSubscribedEvents()
    {
        return [
            'state_enter.order_return.state.in_progress' => 'onProgressRefund',
        ];
    }

    public function onProgressRefund(OrderStateMachineStateChangeEvent $event): void
    {
        try {
            $context = $event->getContext();
            $order = $event->getOrder();
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $order->getId()));
            $orderReturn = $this->orderReturnRepository->search($criteria, $context)->first();
            $transaction = $this->nmiTransactionService->getTransactionByOrderId($order->getId(), $context);
            $orderTransactionId = $this->getOrderTransactionIdByOrderId($order->getId(), $context);
            $orderTotalAmount = $order->getAmountTotal();

            if ($transaction && strtolower($transaction->getStatus()) === strtolower(TransactionStatuses::PAID->value)) {
                $postData = [
                    'security_key' => $this->nmiConfigService->getConfig('privateKeyApi'),
                    'type' => 'refund',
                    'transactionid' => $transaction->getTransactionId(),
                    'payment' => 'creditcard',
                    'amount' => $orderReturn->getAmountTotal(),
                ];

                $response = $this->nmiPaymentApiClient->createTransaction($postData);
                if ('SUCCESS' == $response['responsetext']) {
                    if ($orderReturn->getAmountTotal() == $orderTotalAmount) {
                        $this->transactionStateHandler->refund($orderTransactionId, $context);
                    } else {
                        $this->transactionStateHandler->refundPartially($orderTransactionId, $context);
                    }
                }
            }
        } catch (\Exception) {
        }
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
