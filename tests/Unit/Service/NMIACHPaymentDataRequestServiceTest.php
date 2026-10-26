<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Service\NMIACHPaymentDataRequestService;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIPaymentApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

#[CoversClass(NMIACHPaymentDataRequestService::class)]
class NMIACHPaymentDataRequestServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function authorizeAndCaptureProvider(): iterable
    {
        yield 'authorize and capture enabled' => [true];
        yield 'authorize and capture disabled' => [false];
    }

    #[DataProvider('authorizeAndCaptureProvider')]
    public function testAchIsAlwaysSentAsSale(bool $authorizeAndCapture): void
    {
        $config = $this->createMock(NMIConfigService::class);
        $config->method('getConfig')->willReturnCallback(
            static fn (string $key): mixed => match ($key) {
                'authorizeAndCapture' => $authorizeAndCapture,
                'mode' => 'sandbox',
                default => 'key',
            }
        );

        $sentParams = null;
        $apiClient = $this->createMock(NMIPaymentApiClient::class);
        $apiClient->method('createTransaction')->willReturnCallback(
            static function (array $params) use (&$sentParams): array {
                $sentParams = $params;

                return ['response' => '1', 'transactionid' => 'txn-1'];
            }
        );

        $numberRange = $this->createMock(NumberRangeValueGeneratorInterface::class);
        $numberRange->method('getValue')->willReturn('10001');

        $service = new NMIACHPaymentDataRequestService($config, $apiClient, $numberRange, $this->createMock(LoggerInterface::class));

        $result = $service->sendACHPaymentRequestToNMI(
            ['amount' => '50.00', 'token' => 'token', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            new Cart('cart-token'),
            $this->salesChannelContext()
        );

        self::assertTrue($result['success']);
        self::assertIsArray($sentParams);
        self::assertSame('sale', $sentParams['type']);
        self::assertSame('check', $sentParams['payment']);
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setIso('US');

        $address = new CustomerAddressEntity();
        $address->setFirstName('Jane');
        $address->setLastName('Doe');
        $address->setStreet('1 Main St');
        $address->setCity('Springfield');
        $address->setZipcode('12345');
        $address->setCountry($country);

        $customer = new CustomerEntity();
        $customer->setEmail('jane@example.com');
        $customer->setActiveBillingAddress($address);
        $customer->setActiveShippingAddress($address);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
