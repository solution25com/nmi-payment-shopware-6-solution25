<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Storefront\Controller;

use NMIPayment\Service\CardVelocityService;
use NMIPayment\Service\NMIACHPaymentDataRequestService;
use NMIPayment\Service\NMIPaymentDataRequestService;
use NMIPayment\Service\NMIVaultedCustomerService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Storefront\Controller\NMIPaymentController;
use NMIPayment\Validations\PaymentValidation;
use NMIPayment\Validations\VaultOwnershipGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(NMIPaymentController::class)]
class NMIPaymentControllerTest extends TestCase
{
    public function testVelocityDenialStopsTheGatewayCall(): void
    {
        $validator = $this->createMock(PaymentValidation::class);
        $validator->method('validateCreditCardPaymentData')->willReturn([]);

        $paymentService = $this->createMock(NMIPaymentDataRequestService::class);
        $paymentService->expects(self::never())->method('sendPaymentRequestToNMI');

        $velocityService = $this->createMock(CardVelocityService::class);
        $velocityService->method('isEnabled')->willReturn(true);
        $velocityService
            ->expects(self::once())
            ->method('registerAttempt')
            ->with(
                'a1b2c3d4e5f60718293a4b5c6d7e8f90',
                '00f0e0d0c0b0a0908070605040302010',
                '1117|0131'
            )
            ->willReturn(false);

        $controller = new NMIPaymentController(
            $validator,
            $this->createMock(VaultedCustomerService::class),
            $paymentService,
            $this->createMock(NMIVaultedCustomerService::class),
            $this->createMock(NMIACHPaymentDataRequestService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(VaultOwnershipGuard::class),
            $velocityService
        );

        $customer = new CustomerEntity();
        $customer->setId('a1b2c3d4e5f60718293a4b5c6d7e8f90');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getSalesChannelId')->willReturn('00f0e0d0c0b0a0908070605040302010');
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $request = Request::create(
            '/nmi-payment-credit-card',
            'POST',
            content: json_encode([
                'ccnumber' => '6011111111111117',
                'ccexp' => '01/31',
                'card_type' => 'discover',
            ], \JSON_THROW_ON_ERROR)
        );

        $response = $controller->creditCardPayment($request, new Cart('test-token'), $context);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getContent(), true)['success']);
    }
}
