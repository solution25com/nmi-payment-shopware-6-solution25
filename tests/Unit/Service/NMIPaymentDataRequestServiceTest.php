<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Service\NMIPaymentDataRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NMIPaymentDataRequestService::class)]
class NMIPaymentDataRequestServiceTest extends TestCase
{
    private NMIPaymentDataRequestService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(NMIPaymentDataRequestService::class))->newInstanceWithoutConstructor();
    }

    public function testSuccessfulResponseRequiresTransactionId(): void
    {
        $response = $this->service->handleNMIResponse([
            'response' => '1',
            'responsetext' => 'SUCCESS',
        ]);

        self::assertFalse($response['success']);
        self::assertStringContainsString('Payment failed', $response['message']);
    }

    public function testSuccessfulResponseKeepsTransactionId(): void
    {
        $response = $this->service->handleNMIResponse([
            'response' => '1',
            'transactionid' => '123456789',
        ]);

        self::assertTrue($response['success']);
        self::assertSame('123456789', $response['transaction_id']);
    }
}
