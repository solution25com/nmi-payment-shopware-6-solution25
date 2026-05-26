<?php

declare(strict_types=1);

namespace NMIPayment\Installer;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class OrderStateInstaller
{
    public const STATE_TECHNICAL_NAME = 'net_30_invoicing_pending';
    public const STATE_NAME = 'Invoicing Pending (NET 30)';
    public const TRANSITION_ACTION_NAME = 'net_30_invoicing_pending';
    public const STATE_OVERDUE_TECHNICAL_NAME = 'net_30_overdue';
    public const STATE_OVERDUE_NAME = 'Overdue (NET 30)';
    public const OVERDUE_ACTION_NAME = 'net_30_overdue';

    public function __construct(
        private readonly EntityRepository $stateMachineRepository,
        private readonly EntityRepository $stateMachineStateRepository,
        private readonly EntityRepository $stateMachineTransitionRepository
    ) {
    }

    public function install(Context $context): void
    {
        $stateMachineId = $this->getStateMachineId('order_transaction.state', $context);
        if (!$stateMachineId) {
            return;
        }

        $stateId = $this->ensureStateExists($stateMachineId, self::STATE_TECHNICAL_NAME, self::STATE_NAME, $context);
        $this->ensureTransitionsToNetThirty($stateMachineId, $stateId, $context);
        $this->ensureTransitionsFromNetThirty($stateMachineId, $context);
        $this->ensureStateExists($stateMachineId, self::STATE_OVERDUE_TECHNICAL_NAME, self::STATE_OVERDUE_NAME, $context);
        $this->ensureTransitionsFromOverdue($stateMachineId, $context);
        $this->ensureTransitionsToOverdue($stateMachineId, $context);
    }

    private function ensureStateExists(string $stateMachineId, string $technicalName, string $label, Context $context): string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('stateMachineId', $stateMachineId))
            ->addFilter(new EqualsFilter('technicalName', $technicalName));

        $state = $this->stateMachineStateRepository->search($criteria, $context)->first();
        if ($state !== null) {
            return $state->getId();
        }

        $stateId = Uuid::randomHex();

        $this->stateMachineStateRepository->upsert(
            [
                [
                    'id' => $stateId,
                    'stateMachineId' => $stateMachineId,
                    'technicalName' => $technicalName,
                    'translations' => [
                        Defaults::LANGUAGE_SYSTEM => [
                            'name' => $label,
                        ],
                    ],
                ],
            ],
            $context
        );

        return $stateId;
    }

    private function ensureTransitionExists(
        string $stateMachineId,
        string $fromTechnicalName,
        string $toStateId,
        string $actionName,
        Context $context
    ): void {
        $fromStateId = $this->getStateId($stateMachineId, $fromTechnicalName, $context);
        if (!$fromStateId) {
            return;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('stateMachineId', $stateMachineId))
            ->addFilter(new EqualsFilter('fromStateId', $fromStateId))
            ->addFilter(new EqualsFilter('toStateId', $toStateId))
            ->addFilter(new EqualsFilter('actionName', $actionName));

        $transition = $this->stateMachineTransitionRepository->search($criteria, $context)->first();
        if ($transition) {
            return;
        }

        $this->stateMachineTransitionRepository->upsert([
            [
                'id' => Uuid::randomHex(),
                'stateMachineId' => $stateMachineId,
                'fromStateId' => $fromStateId,
                'toStateId' => $toStateId,
                'actionName' => $actionName,
            ],
        ], $context);
    }

    private function ensureTransitionsToNetThirty(string $stateMachineId, string $targetStateId, Context $context): void
    {
        $sources = [
            'open',
            'authorized',
            'in_progress',
            'paid',
            'paid_partially',
            'reminded',
            'failed',
            'chargeback',
            'refunded',
            'refunded_partially',
        ];

        foreach ($sources as $source) {
            $this->ensureTransitionExists(
                $stateMachineId,
                $source,
                $targetStateId,
                self::TRANSITION_ACTION_NAME,
                $context
            );
        }
    }

    private function ensureTransitionsToOverdue(string $stateMachineId, Context $context): void
    {
        $sources = [
            'open',
            'authorized',
            'in_progress',
            'paid',
            'paid_partially',
            'reminded',
            'failed',
            'chargeback',
            'refunded',
            'refunded_partially',
        ];

        foreach ($sources as $source) {
            $this->ensureTransitionExists(
                $stateMachineId,
                $source,
                $this->getStateId($stateMachineId, self::STATE_OVERDUE_TECHNICAL_NAME, $context),
                self::OVERDUE_ACTION_NAME,
                $context
            );
        }
    }

    private function ensureTransitionsFromNetThirty(string $stateMachineId, Context $context): void
    {
        $transitions = [
            ['action' => 'reopen', 'to' => 'open'],
            ['action' => 'paid', 'to' => 'paid'],
            ['action' => 'paid_partially', 'to' => 'paid_partially'],
            ['action' => 'cancel', 'to' => 'cancelled'],
            ['action' => 'fail', 'to' => 'failed'],
            ['action' => 'process', 'to' => 'in_progress'],
            ['action' => 'authorize', 'to' => 'authorized'],
            ['action' => 'refund', 'to' => 'refunded'],
            ['action' => 'refund_partially', 'to' => 'refunded_partially'],
            ['action' => 'chargeback', 'to' => 'chargeback'],
            ['action' => self::OVERDUE_ACTION_NAME, 'to' => self::STATE_OVERDUE_TECHNICAL_NAME],
        ];

        foreach ($transitions as $transition) {
            $toStateId = $this->getStateId($stateMachineId, $transition['to'], $context);
            if (!$toStateId) {
                continue;
            }

            $this->ensureTransitionExists(
                $stateMachineId,
                self::STATE_TECHNICAL_NAME,
                $toStateId,
                $transition['action'],
                $context
            );
        }
    }

    private function getStateMachineId(string $technicalName, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', $technicalName));

        $stateMachine = $this->stateMachineRepository->search($criteria, $context)->first();

        return $stateMachine ? $stateMachine->getId() : null;
    }

    private function getStateId(string $stateMachineId, string $technicalName, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('stateMachineId', $stateMachineId))
            ->addFilter(new EqualsFilter('technicalName', $technicalName));

        $state = $this->stateMachineStateRepository->search($criteria, $context)->first();

        return $state?->getId();
    }

    private function ensureTransitionsFromOverdue(string $stateMachineId, Context $context): void
    {
        $transitions = [
            ['action' => 'paid', 'to' => 'paid'],
            ['action' => 'fail', 'to' => 'failed'],
            ['action' => 'cancel', 'to' => 'cancelled'],
            ['action' => 'reopen', 'to' => 'open'],
            ['action' => 'process', 'to' => 'in_progress'],
            ['action' => 'authorize', 'to' => 'authorized'],
        ];

        foreach ($transitions as $transition) {
            $toStateId = $this->getStateId($stateMachineId, $transition['to'], $context);
            if (!$toStateId) {
                continue;
            }

            $this->ensureTransitionExists(
                $stateMachineId,
                self::STATE_OVERDUE_TECHNICAL_NAME,
                $toStateId,
                $transition['action'],
                $context
            );
        }
    }
}
