<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiRefundService;
use NMIPayment\Service\NmiTransactionService;
use NMIPayment\Service\RefundLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

#[CoversClass(NmiRefundService::class)]
class NmiRefundServiceTest extends TestCase
{
    private const RETURN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORDER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private NMIPaymentApiClient&MockObject $apiClient;
    private RefundLedger&MockObject $ledger;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(NMIPaymentApiClient::class);
        $this->ledger = $this->createMock(RefundLedger::class);
        $this->ledger->method('lockOrder')->willReturn(true);
        $this->ledger->method('totalsForOrder')->willReturn(['succeeded' => 0.0, 'pending' => 0.0]);
    }

    public function testRefundsTheReturnOnceAndRecordsIt(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 0.0);
        $this->ledger->expects(self::once())->method('claim')->with(self::RETURN_ID, self::ORDER_ID, 30.0)->willReturn(true);
        $this->ledger->expects(self::once())->method('markSucceeded')->with(self::RETURN_ID, 'refund-1');
        $this->apiClient->expects(self::once())->method('createTransaction')
            ->with(self::callback(static fn (array $p): bool => $p['type'] === 'refund' && $p['amount'] === '30.00' && $p['transactionid'] === 'txn-1'))
            ->willReturn(['response' => '1', 'transactionid' => 'refund-1']);

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_REFUNDED, $outcome['result']);
        self::assertFalse($outcome['fullyRefunded']);
    }

    public function testAmountAboveTheRefundableBalanceIsRefused(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 80.0);
        $this->ledger->expects(self::never())->method('claim');
        $this->apiClient->expects(self::never())->method('createTransaction');

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_REFUSED, $outcome['result']);
    }

    public function testAnAlreadyClaimedSourceIsNotRefundedAgain(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 0.0);
        $this->ledger->method('claim')->willReturn(false);
        $this->apiClient->expects(self::never())->method('createTransaction');

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_SKIPPED, $outcome['result']);
    }

    public function testRemainingBalanceIsRefundedWhenNoAmountIsGiven(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 25.0);
        $this->ledger->expects(self::once())->method('claim')->with(self::RETURN_ID, self::ORDER_ID, 75.0)->willReturn(true);
        $this->apiClient->method('createTransaction')->willReturn(['response' => '1', 'transactionid' => 'refund-2']);

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', null, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_REFUNDED, $outcome['result']);
        self::assertTrue($outcome['fullyRefunded']);
    }

    public function testNothingLeftIsSkippedWithoutAGatewayCall(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 100.0);
        $this->apiClient->expects(self::never())->method('createTransaction');

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', null, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_SKIPPED, $outcome['result']);
    }

    public function testDeclineReleasesTheClaim(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 0.0);
        $this->ledger->method('claim')->willReturn(true);
        $this->ledger->expects(self::once())->method('release')->with(self::RETURN_ID);
        $this->ledger->expects(self::never())->method('markSucceeded');
        $this->apiClient->method('createTransaction')->willReturn(['response' => '2', 'responsetext' => 'DECLINE']);

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_DECLINED, $outcome['result']);
    }

    public function testUnknownOutcomeKeepsTheClaim(): void
    {
        $this->amountsAtNmi(captured: 100.0, refunded: 0.0);
        $this->ledger->method('claim')->willReturn(true);
        $this->ledger->expects(self::never())->method('release');
        $this->apiClient->method('createTransaction')->willReturn(null);

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_UNKNOWN, $outcome['result']);
    }

    public function testUnconfirmedAmountsAreRefused(): void
    {
        $this->apiClient->method('queryTransactionAmounts')->willReturn([
            'resolved' => false, 'found' => false, 'authAmount' => null, 'condition' => null, 'capturedAmount' => 0.0, 'refundedAmount' => 0.0,
        ]);
        $this->apiClient->expects(self::never())->method('createTransaction');

        $outcome = $this->service()->refund(self::RETURN_ID, self::ORDER_ID, 'sc', 30.0, 'creditcard', Context::createDefaultContext());

        self::assertSame(NmiRefundService::RESULT_REFUSED, $outcome['result']);
    }

    private function amountsAtNmi(float $captured, float $refunded): void
    {
        $this->apiClient->method('queryTransactionAmounts')->willReturn([
            'resolved' => true,
            'found' => true,
            'authAmount' => $captured,
            'condition' => 'complete',
            'capturedAmount' => $captured,
            'refundedAmount' => $refunded,
        ]);
    }

    private function service(): NmiRefundService
    {
        $transaction = new NmiTransactionEntity();
        $transaction->setId('cccccccccccccccccccccccccccccccc');
        $transaction->setTransactionId('txn-1');

        $transactionService = $this->createMock(NmiTransactionService::class);
        $transactionService->method('getTransactionByOrderId')->willReturn($transaction);

        $config = $this->createMock(NMIConfigService::class);
        $config->method('getConfig')->willReturn('sandbox');

        return new NmiRefundService($transactionService, $this->apiClient, $config, $this->ledger, $this->createMock(LoggerInterface::class));
    }
}
