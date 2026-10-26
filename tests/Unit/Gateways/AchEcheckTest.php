<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Gateways;

use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Library\Constants\TransactionStatuses;
use NMIPayment\Service\AuthorizedTransactionSession;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiTransactionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(AchEcheck::class)]
class AchEcheckTest extends TestCase
{
    private const ORDER_TRANSACTION_ID = 'dddddddddddddddddddddddddddddddd';

    private OrderTransactionStateHandler&MockObject $stateHandler;
    private NmiTransactionService&MockObject $transactionService;
    private NMIPaymentApiClient&MockObject $apiClient;

    protected function setUp(): void
    {
        $this->stateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionService = $this->createMock(NmiTransactionService::class);
        $this->apiClient = $this->createMock(NMIPaymentApiClient::class);
    }

    public function testATransactionIdThisSessionDidNotCreateIsRefusedWithoutAVoid(): void
    {
        $this->apiClient->expects(self::never())->method('queryTransaction');
        $this->apiClient->expects(self::never())->method('voidTransaction');
        $this->transactionService->expects(self::never())->method('addTransaction');
        $this->stateHandler->expects(self::once())->method('fail')->with(self::ORDER_TRANSACTION_ID);

        $request = $this->request('made-up-id');

        $this->expectException(\RuntimeException::class);
        $this->gateway()->pay($request, new PaymentTransactionStruct(self::ORDER_TRANSACTION_ID), Context::createDefaultContext(), null);
    }

    public function testOwnTransactionWithTheWrongAmountIsVoided(): void
    {
        $this->apiClient->method('queryTransaction')->willReturn(['condition' => 'pendingsettlement', 'amount' => '1.00', 'order_id' => '']);
        $this->apiClient->expects(self::once())->method('voidTransaction')->with('sales-channel', 'txn-1', 'check');
        $this->transactionService->expects(self::never())->method('addTransaction');

        $request = $this->request('txn-1');
        AuthorizedTransactionSession::remember($request, 'txn-1');

        $this->expectException(\RuntimeException::class);
        $this->gateway()->pay($request, new PaymentTransactionStruct(self::ORDER_TRANSACTION_ID), Context::createDefaultContext(), null);
    }

    public function testOwnTransactionInTheAuthorizedStateIsVoided(): void
    {
        $this->apiClient->method('queryTransaction')->willReturn(['condition' => 'pending', 'amount' => '50.00', 'order_id' => '10001']);
        $this->apiClient->expects(self::once())->method('voidTransaction')->with('sales-channel', 'txn-1', 'check');
        $this->transactionService->expects(self::never())->method('addTransaction');
        $this->stateHandler->expects(self::never())->method('authorize');
        $this->stateHandler->expects(self::once())->method('fail')->with(self::ORDER_TRANSACTION_ID);

        $request = $this->request('txn-1');
        AuthorizedTransactionSession::remember($request, 'txn-1');

        $this->expectException(\RuntimeException::class);
        $this->gateway()->pay($request, new PaymentTransactionStruct(self::ORDER_TRANSACTION_ID), Context::createDefaultContext(), null);
    }

    public function testOwnVerifiedTransactionIsRecordedAndPaid(): void
    {
        $this->apiClient->method('queryTransaction')->willReturn(['condition' => 'pendingsettlement', 'amount' => '50.00', 'order_id' => '10001']);
        $this->apiClient->expects(self::never())->method('voidTransaction');
        $this->transactionService->expects(self::once())->method('addTransaction')
            ->with(self::anything(), self::anything(), 'txn-1', null, false, TransactionStatuses::PAID->value);
        $this->stateHandler->expects(self::never())->method('authorize');
        $this->stateHandler->expects(self::once())->method('paid')->with(self::ORDER_TRANSACTION_ID);

        $request = $this->request('txn-1');
        AuthorizedTransactionSession::remember($request, 'txn-1');

        $this->gateway()->pay($request, new PaymentTransactionStruct(self::ORDER_TRANSACTION_ID), Context::createDefaultContext(), null);

        self::assertFalse(AuthorizedTransactionSession::consumeMatches($request, 'txn-1'), 'the record is single use');
    }

    private function gateway(): AchEcheck
    {
        $order = new OrderEntity();
        $order->setId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $order->setSalesChannelId('sales-channel');
        $order->setOrderNumber('10001');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId(self::ORDER_TRANSACTION_ID);
        $orderTransaction->setOrderId($order->getId());
        $orderTransaction->setOrder($order);
        $orderTransaction->setAmount(new CalculatedPrice(50.0, 50.0, new CalculatedTaxCollection(), new TaxRuleCollection()));

        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new OrderTransactionCollection([$orderTransaction]));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return new AchEcheck(
            $this->stateHandler,
            $this->transactionService,
            $repository,
            $this->apiClient,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function request(string $postedTransactionId): Request
    {
        $request = new Request([], ['nmi_transaction_id' => $postedTransactionId]);
        $request->attributes->set('sw-sales-channel-id', 'sales-channel');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
