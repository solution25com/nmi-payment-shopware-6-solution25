<?php

declare(strict_types=1);

namespace NMIPayment\Storefront\Controller;

use NMIPayment\Service\NMIPaymentDataRequestService;
use NMIPayment\Service\NMIACHPaymentDataRequestService;
use NMIPayment\Service\NMIVaultedCustomerService;
use NMIPayment\Service\VaultedCustomerService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use NMIPayment\Validations\PaymentValidation;
#[Route(defaults: ['_routeScope' => ['storefront'], "_loginRequired" => true, "_loginRequiredAllowGuest" => true])]
class NMIPaymentController extends StorefrontController
{
  private readonly PaymentValidation $validator;
  private  VaultedCustomerService $vaultedCustomerService;
  private  NMIPaymentDataRequestService $nmiPaymentDataRequestService;
  private  NMIVaultedCustomerService $nmiVaultedCustomerService;
  private  NMIACHPaymentDataRequestService $nmiACHPaymentDataRequestService;
  private readonly LoggerInterface $logger;

  public function __construct(
    PaymentValidation            $validator,
    VaultedCustomerService       $vaultedCustomerService,
    NMIPaymentDataRequestService $nmiPaymentDataRequestService,
    NMIVaultedCustomerService    $nmiVaultedCustomerService,
    NMIACHPaymentDataRequestService $nmiACHPaymentDataRequestService,
    LoggerInterface              $logger
  )
  {
    $this->validator = $validator;
    $this->vaultedCustomerService = $vaultedCustomerService;
    $this->nmiPaymentDataRequestService = $nmiPaymentDataRequestService;
    $this->nmiVaultedCustomerService = $nmiVaultedCustomerService;
    $this->nmiACHPaymentDataRequestService = $nmiACHPaymentDataRequestService;
    $this->logger = $logger;
  }

  #[Route(
    path: '/nmi-payment-credit-card',
    name: 'frontend.nmi-credit-card.payment',
    methods: ['POST']
  )]
  public function creditCardPayment(Request $request, Cart $cart, SalesChannelContext $context): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $validationErrors = $this->validator->validateCreditCardPaymentData($data);

    if (!empty($validationErrors)) {
      return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
    }

    try {
      $paymentResponse = $this->nmiPaymentDataRequestService->sendPaymentRequestToNMI($data, $cart, $context);

      if ($paymentResponse['success'] && !empty($paymentResponse['customer_vault_id'])) {
        $vaultedCustomerId = $paymentResponse['customer_vault_id'];

        $existingBillingRecord = $this->vaultedCustomerService->getBillingIdByVaultedId(
          $context->getContext(),
          $vaultedCustomerId
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

        $this->vaultedCustomerService->store($context, $vaultedCustomerId, 'null', json_encode($billingArray), $paymentResponse['billing_id']);

      }

      return new JsonResponse($paymentResponse, $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
      $this->logger->error('Payment processing failed', ['exception' => $e]);
      return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  #[Route(
    path: '/nmi-payment-vaulted-customer',
    name: 'frontend.nmi-vaulted-customer.payment',
    methods: ['POST']
  )]
  public function vaultedCustomerPayment(Request $request,Cart $cart, SalesChannelContext $context): JsonResponse
  {
    $data = json_decode($request->getContent(), true);
    $validationErrors = $this->validator->validateVaultedCustomer($data);
    if (!empty($validationErrors)) {
      return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
    }
    try {
      $paymentResponse = $this->nmiVaultedCustomerService->vaultedCapture($data, $cart, $context);
      return new JsonResponse($paymentResponse, $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
            $this->logger->info('Payment processing failed', ['exception' => $e]);
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
    $data = json_decode($request->getContent(), true);

    try {
      $dataResponse = $this->nmiVaultedCustomerService->sendDataToGetVaultedCustomer($data, $context);
      return new JsonResponse($dataResponse, $dataResponse != null ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
      $this->logger->error('Payment processing failed', [
        'exception_message' => $e->getMessage(),
        'exception_stack_trace' => $e->getTraceAsString(),
        'exception_code' => $e->getCode()
      ]);

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
    $data = json_decode($request->getContent(), true);

    if (empty($data['customer_vault_id'])) {
      return $this->createErrorResponse('Missing customer vault ID', [], Response::HTTP_BAD_REQUEST);
    }

    try {
      $response = $this->nmiVaultedCustomerService->deleteVaultedCustomerData($data['customer_vault_id'], $context);

      if ($response['success']) {
        $this->vaultedCustomerService->delete($context, $data['customer_vault_id']);
      }

      return new JsonResponse($response, $response['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete vaulted customer data', ['exception' => $e]);
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
    $data = json_decode($request->getContent(), true);

    try {
      $paymentResponse = $this->nmiVaultedCustomerService->addMultipleCards($data, $cart, $context);

      if ($paymentResponse['success']) {
        $existingBillingData = $this->vaultedCustomerService->getBillingIdByVaultedId($context->getContext(), $data['vaulted_customer_id'])->getBillingId();

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

        $this->vaultedCustomerService->store($context, $data['vaulted_customer_id'], 'null', json_encode($billingArray), $paymentResponse['billingId']);
      }

      return new JsonResponse($paymentResponse, $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);

    } catch (\Exception $e) {
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
    $data = json_decode($request->getContent(), true);
    $validationErrors = $this->validator->validateACHPaymentData($data);

    if (!empty($validationErrors)) {
      return $this->createErrorResponse('Invalid request data.', $validationErrors, Response::HTTP_BAD_REQUEST);
    }

    try {
      $paymentResponse = $this->nmiACHPaymentDataRequestService->sendACHPaymentRequestToNMI($data, $cart, $context);
      return new JsonResponse($paymentResponse, $paymentResponse['success'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST);
    } catch (\Exception $e) {
      $this->logger->error('ACH Payment processing failed', ['exception' => $e]);
      return $this->createErrorResponse('Payment processing failed due to an internal error.', [], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
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
}