<?php

declare(strict_types=1);

namespace NMIPayment\Command;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\DealerConfigService;
use NMIPayment\Service\NetThirtyFields;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dealer:generate-invoices',
    description: 'Generate invoice documents for dealer (Net30) orders that do not have one yet'
)]
class GenerateDealerInvoicesCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DocumentGenerator $documentGenerator,
        private readonly DealerConfigService $configService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without generating documents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $context = Context::createDefaultContext();
        $context->addState(Context::SKIP_TRIGGER_FLOW);

        $dealerGroups = $this->configService->getDealerCustomerGroups();
        if (empty($dealerGroups)) {
            $io->error('No dealer customer groups configured.');
            return Command::FAILURE;
        }

        $totalFound = 0;
        $totalSkipped = 0;
        $totalSuccessCount = 0;
        $totalErrorCount = 0;
        $offset = 0;

        do {
            $orders = $this->fetchPendingDealerOrders($context, $offset);
            $batchCount = $orders->count();
            $totalFound += $batchCount;

            [$operations, $skipped] = $this->buildOperations($orders, $io, $dryRun);
            $totalSkipped += $skipped;

            if (!$dryRun && !empty($operations)) {
                try {
                    $result = $this->documentGenerator->generate('invoice', $operations, $context);

                    $totalSuccessCount += count($result->getSuccess());

                    foreach ($result->getErrors() as $orderId => $error) {
                        $totalErrorCount++;
                        $io->error(sprintf('Error for order %s: %s', $orderId, $error->getMessage()));
                    }
                } catch (\Throwable $e) {
                    $totalErrorCount += count($operations);
                    $io->error(sprintf('Batch failed: %s', $e->getMessage()));
                }
            }

            $offset += self::BATCH_SIZE;
        } while ($batchCount === self::BATCH_SIZE);

        $io->newLine();
        $io->writeln(sprintf('Total orders found: %d', $totalFound));
        $io->writeln(sprintf('Skipped (already have invoice): %d', $totalSkipped));
        $io->writeln(sprintf('Generated: %d', $totalSuccessCount));

        if ($dryRun) {
            $io->writeln(sprintf('To generate: %d', $totalFound - $totalSkipped));
            $io->success('Dry run complete. No documents generated.');
            return Command::SUCCESS;
        }

        if ($totalSuccessCount === 0 && $totalErrorCount === 0) {
            $io->success('Nothing to generate.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Generated %d invoices. Errors: %d.', $totalSuccessCount, $totalErrorCount));

        return $totalErrorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function fetchPendingDealerOrders(Context $context, int $offset): OrderCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('customFields.' . NetThirtyFields::DEALER_PAYMENT_TYPE, NetThirtyFields::DEALER_PAYMENT_TYPE_NET30)
        );
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('transactions.stateMachineState.technicalName', OrderStateInstaller::STATE_TECHNICAL_NAME),
                new EqualsFilter('transactions.stateMachineState.technicalName', OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME),
            ])
        );
        $criteria->addAssociation('documents.documentType');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->setLimit(self::BATCH_SIZE);
        $criteria->setOffset($offset);

        /** @var OrderCollection $orders */
        $orders = $this->orderRepository->search($criteria, $context)->getEntities();

        return $orders;
    }

    /**
     * @return array{array<string, DocumentGenerateOperation>, int}
     */
    private function buildOperations(OrderCollection $orders, SymfonyStyle $io, bool $dryRun): array
    {
        $operations = [];
        $skipped = 0;

        foreach ($orders as $order) {
            $hasInvoice = $order->getDocuments()?->filter(
                fn ($doc) => $doc->getDocumentType()?->getTechnicalName() === 'invoice'
            )->count() > 0;

            if ($hasInvoice) {
                $skipped++;
                continue;
            }

            $io->writeln(sprintf(
                '  %s Order %s (%.2f)',
                $dryRun ? '[DRY-RUN]' : '[GENERATE]',
                $order->getOrderNumber(),
                $order->getAmountTotal()
            ));

            if (!$dryRun) {
                $operations[$order->getId()] = new DocumentGenerateOperation(
                    $order->getId(),
                    'pdf',
                    ['displayInCustomerAccount' => true]
                );
            }
        }

        return [$operations, $skipped];
    }
}
