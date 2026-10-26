<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Storefront\Controller;

use NMIPayment\Service\AuthorizedTransactionSession;
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
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NMIPaymentController::class)]
class NMIPaymentControllerTest extends TestCase
{
    private const CUSTOMER_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const SALES_CHANNEL_ID = '00f0e0d0c0b0a0908070605040302010';

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
            ->with(self::CUSTOMER_ID, self::SALES_CHANNEL_ID, '1117|0131')
            ->willReturn(false);

        $controller = $this->controller(validator: $validator, paymentService: $paymentService, velocityService: $velocityService);

        $response = $controller->creditCardPayment($this->jsonRequest([
            'ccnumber' => '6011111111111117',
            'ccexp' => '01/31',
            'card_type' => 'discover',
        ]), new Cart('test-token'), $this->salesChannelContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['success']);
        self::assertSame('nmiPayment.payment.contactCustomerService', $body['message']);
    }

    public function testSuccessfulCardAuthIsRememberedForPay(): void
    {
        $validator = $this->createMock(PaymentValidation::class);
        $validator->method('validateCreditCardPaymentData')->willReturn([]);

        $paymentService = $this->createMock(NMIPaymentDataRequestService::class);
        $paymentService->method('sendPaymentRequestToNMI')->willReturn([
            'success' => true,
            'customer_vault_id' => null,
            'responses' => ['payment' => ['success' => true, 'transaction_id' => '10001']],
        ]);

        $request = $this->jsonRequest(['ccnumber' => '1111', 'ccexp' => '0131', 'card_type' => 'visa']);
        $response = $this->controller(validator: $validator, paymentService: $paymentService)
            ->creditCardPayment($request, new Cart('test-token'), $this->salesChannelContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('10001', $request->getSession()->get(AuthorizedTransactionSession::SESSION_KEY));
    }

    public function testGatewayDeclineTextIsReplacedWithGenericMessage(): void
    {
        $validator = $this->createMock(PaymentValidation::class);
        $validator->method('validateCreditCardPaymentData')->willReturn([]);

        $paymentService = $this->createMock(NMIPaymentDataRequestService::class);
        $paymentService->method('sendPaymentRequestToNMI')->willReturn([
            'success' => false,
            'message' => 'Payment failed: CVV2/CVC2 Mismatch',
        ]);

        $request = $this->jsonRequest(['ccnumber' => '1111', 'ccexp' => '0131', 'card_type' => 'visa']);
        $response = $this->controller(validator: $validator, paymentService: $paymentService)
            ->creditCardPayment($request, new Cart('test-token'), $this->salesChannelContext());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('nmiPayment.payment.declined', $body['message']);
        self::assertFalse($request->getSession()->has(AuthorizedTransactionSession::SESSION_KEY));
    }

    public function testVaultLookupIsRefusedForAForeignVault(): void
    {
        $guard = $this->createMock(VaultOwnershipGuard::class);
        $guard->method('assertVaultOwnership')
            ->with('foreign-vault', self::anything())
            ->willReturn(new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN));

        $vaultService = $this->createMock(NMIVaultedCustomerService::class);
        $vaultService->expects(self::never())->method('sendDataToGetVaultedCustomer');

        $response = $this->controller(vaultService: $vaultService, guard: $guard)
            ->getVaultedCustomer($this->jsonRequest(['customer_vault_id' => 'foreign-vault']), $this->salesChannelContext());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testVaultedPaymentChecksTheSelectedCardToo(): void
    {
        $validator = $this->createMock(PaymentValidation::class);
        $validator->method('validateVaultedCustomer')->willReturn([]);

        $guard = $this->createMock(VaultOwnershipGuard::class);
        $guard->expects(self::once())
            ->method('assertVaultOwnership')
            ->with('own-vault', self::anything(), 'someone-elses-card')
            ->willReturn(new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN));

        $vaultService = $this->createMock(NMIVaultedCustomerService::class);
        $vaultService->expects(self::never())->method('vaultedCapture');

        $response = $this->controller(validator: $validator, vaultService: $vaultService, guard: $guard)
            ->vaultedCustomerPayment(
                $this->jsonRequest(['customer_vault_id' => 'own-vault', 'billing_id' => 'someone-elses-card', 'amount' => '10.00']),
                new Cart('test-token'),
                $this->salesChannelContext()
            );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testNonObjectJsonIsRefused(): void
    {
        $request = Request::create('/nmi-payment-credit-card', 'POST', content: '"just a string"');

        $response = $this->controller()->creditCardPayment($request, new Cart('test-token'), $this->salesChannelContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    private function controller(
        ?PaymentValidation $validator = null,
        ?NMIPaymentDataRequestService $paymentService = null,
        ?NMIVaultedCustomerService $vaultService = null,
        ?VaultOwnershipGuard $guard = null,
        ?CardVelocityService $velocityService = null
    ): NMIPaymentController {
        $controller = new NMIPaymentController(
            $validator ?? $this->createMock(PaymentValidation::class),
            $this->createMock(VaultedCustomerService::class),
            $paymentService ?? $this->createMock(NMIPaymentDataRequestService::class),
            $vaultService ?? $this->createMock(NMIVaultedCustomerService::class),
            $this->createMock(NMIACHPaymentDataRequestService::class),
            $this->createMock(LoggerInterface::class),
            $guard ?? $this->createMock(VaultOwnershipGuard::class),
            $velocityService ?? $this->createMock(CardVelocityService::class)
        );

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $container = new Container();
        $container->set('translator', $translator);
        $controller->setContainer($container);

        return $controller;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId(self::CUSTOMER_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $request = Request::create('/nmi', 'POST', content: json_encode($body, \JSON_THROW_ON_ERROR));
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
