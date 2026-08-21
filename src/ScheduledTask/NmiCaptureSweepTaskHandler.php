<?php

declare(strict_types=1);

namespace NMIPayment\ScheduledTask;

use NMIPayment\Gateways\CreditCard;
use NMIPayment\Service\NmiCaptureService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class NmiCaptureSweepTaskHandler extends ScheduledTaskHandler
{
    private const LOOKBACK_DAYS = 14;
    private const AGED_HOURS = 1;
    private const PAGE_SIZE = 100;
    private const MAX_ORDERS_PER_RUN = 1000;
    private const SHIPPED_STATES = ['shipped', 'shipped_partially'];

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly EntityRepository $orderRepository,
        private readonly NmiCaptureService $captureService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public static function getHandledMessages(): iterable
    {
        return [NmiCaptureSweepTask::class];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        $now = new \DateTimeImmutable();
        $since = $now->modify('-' . self::LOOKBACK_DAYS . ' days');
        $agedThreshold = $now->modify('-' . self::AGED_HOURS . ' hours');
        $candidates = $this->collectCandidates($context, $since);
        $results = [];

        $this->logger->info('[NMI capture sweep] started', [
            'candidates' => \count($candidates),
            'since' => $since->format(\DATE_ATOM),
        ]);

        foreach ($candidates as $candidate) {
            try {
                $result = $this->captureService->captureForOrder($candidate['id'], $context);
                $results[$result] = ($results[$result] ?? 0) + 1;

                if (\in_array($result, [NmiCaptureService::RESULT_CAPTURED, NmiCaptureService::RESULT_VOIDED_ZERO], true)) {
                    $this->logger->info('[NMI capture sweep] order settled', [
                        'orderId' => $candidate['id'],
                        'orderNumber' => $candidate['orderNumber'],
                        'result' => $result,
                    ]);
                }

                $agedFrom = $candidate['shippedAt'] ?? $candidate['createdAt'];
                if (\in_array($result, [NmiCaptureService::RESULT_DEFERRED, NmiCaptureService::RESULT_FAILED], true)
                    && $agedFrom instanceof \DateTimeInterface
                    && $agedFrom < $agedThreshold) {
                    $ageHours = round(($now->getTimestamp() - $agedFrom->getTimestamp()) / 3600, 1);
                    $this->logger->warning('[NMI capture sweep] order remains uncaptured after shipment', [
                        'orderId' => $candidate['id'],
                        'orderNumber' => $candidate['orderNumber'],
                        'orderTotal' => $candidate['amountTotal'],
                        'ageHours' => $ageHours,
                        'result' => $result,
                    ]);
                }
            } catch (\Throwable $exception) {
                $results['exception'] = ($results['exception'] ?? 0) + 1;
                $this->logger->error('[NMI capture sweep] capture failed', [
                    'orderId' => $candidate['id'],
                    'orderNumber' => $candidate['orderNumber'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->logger->info('[NMI capture sweep] completed', [
            'candidates' => \count($candidates),
            'results' => $results,
        ]);
    }

    /**
     * Snapshot candidates before capture mutates the filtered result set.
     *
     * @return list<array{id: string, orderNumber: string, createdAt: ?\DateTimeInterface, shippedAt: ?\DateTimeInterface, amountTotal: float}>
     */
    private function collectCandidates(Context $context, \DateTimeImmutable $since): array
    {
        $candidates = [];
        $offset = 0;

        do {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('transactions.paymentMethod.handlerIdentifier', CreditCard::class));
            $criteria->addFilter(new EqualsAnyFilter('transactions.stateMachineState.technicalName', ['paid', 'paid_partially']));
            $criteria->addFilter(new EqualsFilter('customFields.NmiPaymentAmountCapture', null));
            $criteria->addFilter(new EqualsFilter('customFields.nmiCaptureBlocked', null));
            $criteria->addFilter(new EqualsAnyFilter('deliveries.stateMachineState.technicalName', self::SHIPPED_STATES));
            $criteria->addFilter(new RangeFilter('deliveries.updatedAt', [
                RangeFilter::GTE => $since->format(\DATE_ATOM),
            ]));
            $criteria->addAssociation('deliveries.stateMachineState');
            $criteria->addSorting(new FieldSorting('deliveries.updatedAt', FieldSorting::DESCENDING));
            $criteria->setLimit(self::PAGE_SIZE);
            $criteria->setOffset($offset);

            $orders = $this->orderRepository->search($criteria, $context)->getEntities();
            if ($orders->count() === 0) {
                break;
            }

            /** @var OrderEntity $order */
            foreach ($orders as $order) {
                $candidates[] = [
                    'id' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'createdAt' => $order->getCreatedAt(),
                    'shippedAt' => $this->latestShipTimestamp($order),
                    'amountTotal' => $order->getAmountTotal(),
                ];
            }

            $offset += self::PAGE_SIZE;
        } while ($orders->count() === self::PAGE_SIZE && $offset < self::MAX_ORDERS_PER_RUN);

        return $candidates;
    }

    private function latestShipTimestamp(OrderEntity $order): ?\DateTimeInterface
    {
        $shippedAt = null;

        foreach ($order->getDeliveries() ?? [] as $delivery) {
            if (!\in_array($delivery->getStateMachineState()?->getTechnicalName(), self::SHIPPED_STATES, true)) {
                continue;
            }

            $candidate = $delivery->getUpdatedAt() ?? $delivery->getCreatedAt();
            if ($candidate !== null && ($shippedAt === null || $candidate > $shippedAt)) {
                $shippedAt = $candidate;
            }
        }

        return $shippedAt;
    }
}
