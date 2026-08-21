<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Core\Content\Transaction\NmiTransactionEntity;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Service\NmiCaptureService;
use NMIPayment\Service\NmiTransactionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

#[CoversClass(NmiCaptureService::class)]
class NmiCaptureServiceTest extends TestCase
{
    public function testCaptureIsCappedAtAuthorizedAmount(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->order();

        $orderSearch = $this->createMock(EntitySearchResult::class);
        $orderSearch->method('first')->willReturn($order);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturn($orderSearch);
        $orderRepository
            ->expects(self::once())
            ->method('update')
            ->with(self::callback(static function (array $rows): bool {
                return $rows[0]['customFields']['NmiPaymentAmountCapture'] === 80.0
                    && $rows[0]['customFields']['nmiCaptureShortfall'] === 20.0;
            }));

        $nmiTransaction = new NmiTransactionEntity();
        $nmiTransaction->setId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $nmiTransaction->setTransactionId('gateway-transaction');
        $nmiTransaction->setAuthAmount(80.0);

        $transactionService = $this->createMock(NmiTransactionService::class);
        $transactionService->method('getTransactionByOrderId')->willReturn($nmiTransaction);
        $transactionService
            ->expects(self::once())
            ->method('updateTransactionStatus')
            ->with($order->getId(), 'paid', $context);

        $apiClient = $this->createMock(NMIPaymentApiClient::class);
        $apiClient->expects(self::once())->method('initializeForSalesChannel')->with('sales-channel');
        $apiClient
            ->expects(self::once())
            ->method('createTransaction')
            ->with(self::callback(static fn (array $payload): bool => $payload['type'] === 'capture'
                && $payload['amount'] === 80.0
                && $payload['transactionid'] === 'gateway-transaction'))
            ->willReturn(['response' => '1', 'transactionid' => 'capture-transaction']);

        $configService = $this->createMock(NMIConfigService::class);
        $configService->method('getConfig')->willReturnMap([
            ['mode', 'sales-channel', 'sandbox'],
            ['privateKeyApi', 'sales-channel', 'secret'],
        ]);

        $service = new NmiCaptureService(
            $transactionService,
            $apiClient,
            $configService,
            $orderRepository,
            null,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class)
        );

        self::assertSame(NmiCaptureService::RESULT_CAPTURED, $service->captureForOrder($order->getId(), $context));
    }

    private function order(): OrderEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('cccccccccccccccccccccccccccccccc');
        $paymentMethod->setHandlerIdentifier(CreditCard::class);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('dddddddddddddddddddddddddddddddd');
        $transaction->setPaymentMethod($paymentMethod);

        $order = new OrderEntity();
        $order->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel');
        $order->setAmountTotal(100.0);
        $order->setCustomFields([]);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }
}
