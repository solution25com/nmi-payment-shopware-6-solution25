<?php

declare(strict_types=1);

namespace NMIPayment\Storefront\Controller;

use NMIPayment\Service\AuthorizedTransactionSession;
use NMIPayment\Service\NMIPaymentDataRequestService;
use NMIPayment\Service\NMIACHPaymentDataRequestService;
use NMIPayment\Service\NMIVaultedCustomerService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Service\CardVelocityService;
use NMIPayment\Validations\PaymentValidation;
use NMIPayment\Validations\VaultOwnershipGuard;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

#[Route(defaults: ['_routeScope' => ['storefront'], "_loginRequired" => true, "_loginRequiredAllowGuest" => true])]
class NMIPaymentController extends StorefrontController
{
    private readonly PaymentValidation $validator;
    private VaultedCustomerService $vaultedCustomerService;
    private NMIPaymentDataRequestService $nmiPaymentDataRequestService;
    private NMIVaultedCustomerService $nmiVaultedCustomerService;
    private NMIACHPaymentDataRequestService $nmiACHPaymentDataRequestService;
    private readonly LoggerInterface $logger;
    private readonly VaultOwnershipGuard $vaultOwnershipGuard;
    private readonly CardVelocityService $cardVelocityService;

    public function __construct(
        PaymentValidation $validator,
        VaultedCustomerService $vaultedCustomerService,
        NMIPaymentDataRequestService $nmiPaymentDataRequestService,
        NMIVaultedCustomerService $nmiVaultedCustomerService,
        NMIACHPaymentDataRequestService $nmiACHPaymentDataRequestService,
        LoggerInterface $logger,
        VaultOwnershipGuard $vaultOwnershipGuard,
        CardVelocityService $cardVelocityService
    ) {
        $this->validator = $validator;
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->nmiPaymentDataRequestService = $nmiPaymentDataRequestService;
        $this->nmiVaultedCustomerService = $nmiVaultedCustomerService;
        $this->nmiACHPaymentDataRequestService = $nmiACHPaymentDataRequestService;
        $this->logger = $logger;
        $this->vaultOwnershipGuard = $vaultOwnershipGuard;
        $this->cardVelocityService = $cardVelocityService;
    }

    #[Route(
        path: '/nmi-payment-credit-card',
        name: 'frontend.nmi-credit-card.payment',
        methods: ['POST']
    )]
    public function creditCardPayment(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }
        $validationErrors = $this->validator->validateCreditCardPaymentData($data);

        if (!empty($validationErrors)) {
            return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
        }

        $velocityDenial = $this->denyIfTooManyDistinctCards($data, $context);
        if ($velocityDenial !== null) {
            return $velocityDenial;
        }

        try {
            $paymentResponse = $this->nmiPaymentDataRequestService->sendPaymentRequestToNMI($data, $cart, $context);

            if ($paymentResponse['success']) {
                AuthorizedTransactionSession::remember($request, $paymentResponse['responses']['payment']['transaction_id'] ?? null);
            }

            if ($paymentResponse['success'] && !empty($paymentResponse['customer_vault_id'])) {
                $vaultedCustomerId = (string) $paymentResponse['customer_vault_id'];

                $existingBillingRecord = $this->vaultedCustomerService->getBillingIdByVaultedId(
                    $context->getContext(),
                    $vaultedCustomerId,
                    $context->getCustomer()?->getId()
                );

                $existingBillingData = $existingBillingRecord ? $existingBillingRecord->getBillingId() : null;

                $billingArray = !empty($existingBillingData) ? json_decode($existingBillingData, true) : [];

                if (!is_array($billingArray)) {
                      $billingArray = [];
                }

                $newBillingData = [
                'billingId' => $paymentResponse['billing_id'],
                'cardType' => $data['card_type'],
                'firstName' => $data['first_name'] ?? null,
                'lastName' => $data['last_name'] ?? null,
                'lastDigits' => $data['ccnumber'] ?? null,
                'ccexp' => $data['ccexp'] ?? null,];

                $billingArray[] = $newBillingData;

                $this->vaultedCustomerService->store($context, $vaultedCustomerId, 'null', json_encode($billingArray), (string) $paymentResponse['billing_id']);
            }

            return new JsonResponse($this->withGenericDeclineMessage($paymentResponse), $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Payment processing failed', $this->exceptionContext($e));
            return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/nmi-payment-vaulted-customer',
        name: 'frontend.nmi-vaulted-customer.payment',
        methods: ['POST']
    )]
    public function vaultedCustomerPayment(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }
        $validationErrors = $this->validator->validateVaultedCustomer($data);
        if (!empty($validationErrors)) {
            return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
        }

        $billingId = isset($data['billing_id']) && $data['billing_id'] !== '' ? $data['billing_id'] : null;
        $ownershipError = $this->vaultOwnershipGuard->assertVaultOwnership($data['customer_vault_id'] ?? null, $context, $billingId);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $data['customer_vault_id'] = (string) $data['customer_vault_id'];
        $data['billing_id'] = $billingId !== null ? (string) $billingId : null;

        try {
            $paymentResponse = $this->nmiVaultedCustomerService->vaultedCapture($data, $cart, $context);

            if ($paymentResponse['success']) {
                AuthorizedTransactionSession::remember($request, $paymentResponse['responses']['payment']['transaction_id'] ?? null);
            }

            return new JsonResponse($this->withGenericDeclineMessage($paymentResponse), $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Payment processing failed', $this->exceptionContext($e));
            return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/nmi-payment-get-vaulted-customer',
        name: 'frontend.nmi-get-vaulted-customer.payment',
        methods: ['POST']
    )]
    public function getVaultedCustomer(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }

        $ownershipError = $this->vaultOwnershipGuard->assertVaultOwnership($data['customer_vault_id'] ?? null, $context);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $data['customer_vault_id'] = (string) $data['customer_vault_id'];

        try {
            $dataResponse = $this->nmiVaultedCustomerService->sendDataToGetVaultedCustomer($data, $context);
            return new JsonResponse($dataResponse, $dataResponse != null ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Payment processing failed', $this->exceptionContext($e));

            return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    #[Route(
        path: '/nmi-payment-delete-vaulted-customer',
        name: 'frontend.nmi-delete-vaulted-customer.payment',
        methods: ['POST']
    )]
    public function deleteVaultedCustomer(Request $request, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }

        $ownershipError = $this->vaultOwnershipGuard->assertVaultOwnership($data['customer_vault_id'] ?? null, $context);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        try {
            $response = $this->nmiVaultedCustomerService->deleteVault((string) $data['customer_vault_id'], $context);

            if (!$response['success']) {
                $this->logger->warning('Vault deletion refused by the gateway', ['gateway_message' => $response['message']]);

                return $this->createErrorResponse($this->trans('nmiPayment.savedCards.deleteFailed'), [], Response::HTTP_BAD_GATEWAY);
            }

            return new JsonResponse(['success' => true, 'message' => $response['message']], Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete vaulted customer data', $this->exceptionContext($e));
            return $this->createErrorResponse('Failed to delete customer data due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    #[Route(
        path: '/nmi-add-card',
        name: 'frontend.add-cards.payment',
        methods: ['POST']
    )]
    public function addMultipleCards(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }

        $ownershipError = $this->vaultOwnershipGuard->assertVaultOwnership($data['vaulted_customer_id'] ?? null, $context);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $data['vaulted_customer_id'] = (string) $data['vaulted_customer_id'];

        try {
            $paymentResponse = $this->nmiVaultedCustomerService->addMultipleCards($data, $context);

            if ($paymentResponse['success']) {
                $existingBillingData = $this->vaultedCustomerService->getBillingIdByVaultedId(
                    $context->getContext(),
                    $data['vaulted_customer_id'],
                    $context->getCustomer()?->getId()
                )?->getBillingId();

                $billingArray = !empty($existingBillingData) ? json_decode($existingBillingData, true) : [];

                if (!is_array($billingArray)) {
                    $billingArray = [];
                }

                $newBillingData = [
                'billingId' => $paymentResponse['billingId'],
                'cardType' => $data['card_type'],
                'firstName' => $data['first_name'] ?? null,
                'lastName' => $data['last_name'] ?? null,
                'lastDigits' => $data['ccnumber'] ?? null,
                'ccexp' => $data['ccexp'] ?? null,];

                $billingArray[] = $newBillingData;

                $this->vaultedCustomerService->store($context, $data['vaulted_customer_id'], 'null', json_encode($billingArray), (string) $paymentResponse['billingId']);
            }

            return new JsonResponse($this->withGenericDeclineMessage($paymentResponse), $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to add vaulted card', $this->exceptionContext($e));
            return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/nmi-payment-ach',
        name: 'frontend.nmi-ach.payment',
        methods: ['POST']
    )]
    public function achPayment(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
    {
        $data = $this->decodeJsonObject($request);
        if ($data === null) {
            return $this->createErrorResponse('Invalid request data.', [], Response::HTTP_BAD_REQUEST);
        }
        $validationErrors = $this->validator->validateACHPaymentData($data);

        if (!empty($validationErrors)) {
            return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
        }

        try {
            $paymentResponse = $this->nmiACHPaymentDataRequestService->sendACHPaymentRequestToNMI($data, $cart, $context);

            if ($paymentResponse['success']) {
                AuthorizedTransactionSession::remember($request, $paymentResponse['transaction_id'] ?? null);
            }

            return new JsonResponse($this->withGenericDeclineMessage($paymentResponse), $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('ACH Payment processing failed', $this->exceptionContext($e));
            return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The gateway's decline text (CVV mismatch, AVS, insufficient funds, ...) tells a card tester
     * exactly what to change. The shopper gets one generic message; the gateway text stays in the log.
     */
    private function withGenericDeclineMessage(array $paymentResponse): array
    {
        if (($paymentResponse['success'] ?? false) === true || !isset($paymentResponse['message'])) {
            return $paymentResponse;
        }

        $this->logger->info('[payment] declined', ['gatewayMessage' => $paymentResponse['message']]);
        $paymentResponse['message'] = $this->trans('nmiPayment.payment.declined');

        return $paymentResponse;
    }

    /**
     * Request bodies must be a JSON object. Anything else (malformed JSON, a bare string or number)
     * is refused here rather than reaching array-typed service methods and failing with a 500.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Exception details for the log without the stack trace, whose argument values can carry
     * request data.
     *
     * @return array<string, mixed>
     */
    private function exceptionContext(\Throwable $e): array
    {
        return [
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
        ];
    }

    private function createErrorResponse(string $message, array $errors = [], int $statusCode = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(
            [
            'success' => false,
            'message' => $message,
            'errors' => $errors
            ],
            $statusCode
        );
    }

    private function denyIfTooManyDistinctCards(array $data, SalesChannelContext $context): ?JsonResponse
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return null;
        }

        try {
            if (!$this->cardVelocityService->isEnabled($context->getSalesChannelId())) {
                return null;
            }

            $lastFour = $this->cardLastFour($data['ccnumber'] ?? null);
            $expiry = preg_replace('/[^0-9]/', '', (string) ($data['ccexp'] ?? ''));
            if ($lastFour === '' || $expiry === '') {
                return null;
            }

            $allowed = $this->cardVelocityService->registerAttempt(
                $customer->getId(),
                $context->getSalesChannelId(),
                $lastFour . '|' . $expiry,
                $context->getContext()
            );
        } catch (\Throwable $exception) {
            $this->logger->error('[card-velocity] guard failure, failing open', [
                'error' => $exception->getMessage(),
                'customerId' => $customer->getId(),
                'salesChannelId' => $context->getSalesChannelId(),
            ]);

            return null;
        }

        if ($allowed) {
            return null;
        }

        return $this->createErrorResponse(
            $this->trans('nmiPayment.payment.contactCustomerService'),
            [],
            Response::HTTP_BAD_REQUEST
        );
    }

    private function cardLastFour(?string $cardNumber): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', (string) $cardNumber);

        return $normalized ? substr($normalized, -4) : '';
    }
}
