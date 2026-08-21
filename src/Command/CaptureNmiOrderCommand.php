<?php

declare(strict_types=1);

namespace NMIPayment\Command;

use NMIPayment\Service\NmiCaptureService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'nmi:capture-order',
    description: 'Retry the idempotent NMI capture flow for an order ID or order number.'
)]
class CaptureNmiOrderCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly NmiCaptureService $captureService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('order', InputArgument::REQUIRED, 'Shopware order UUID or order number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderReference = trim((string) $input->getArgument('order'));
        $context = Context::createDefaultContext();
        $criteria = new Criteria();

        if (preg_match('/^[0-9a-f]{32}$/i', $orderReference) === 1) {
            $criteria->setIds([$orderReference]);
        } else {
            $criteria->addFilter(new EqualsFilter('orderNumber', $orderReference));
        }

        $criteria->setLimit(1);
        $order = $this->orderRepository->search($criteria, $context)->first();
        if ($order === null) {
            $output->writeln(sprintf('<error>Order "%s" was not found.</error>', $orderReference));

            return self::FAILURE;
        }

        $result = $this->captureService->captureForOrder($order->getId(), $context);
        $output->writeln(sprintf(
            '<info>NMI capture result for order %s (%s): %s</info>',
            $order->getOrderNumber(),
            $order->getId(),
            $result
        ));

        return \in_array($result, [
            NmiCaptureService::RESULT_CAPTURED,
            NmiCaptureService::RESULT_VOIDED_ZERO,
            NmiCaptureService::RESULT_SKIPPED,
        ], true) ? self::SUCCESS : self::FAILURE;
    }
}
