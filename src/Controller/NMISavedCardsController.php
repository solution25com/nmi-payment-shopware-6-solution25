<?php

namespace NMIPayment\Controller;

use NMIPayment\Service\NMIVaultedCustomerService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;


#[Route(defaults: ['_routeScope' => ['storefront']])]
class NMISavedCardsController  extends StorefrontController
{
  private EntityRepository $vaultedCustomerRepository;
  private NMIVaultedCustomerService $nmiVaultedCustomerService;

  public function __construct(EntityRepository $vaultedCustomerRepository, NMIVaultedCustomerService $nmiVaultedCustomerService)
  {
    $this->vaultedCustomerRepository = $vaultedCustomerRepository;
    $this->nmiVaultedCustomerService = $nmiVaultedCustomerService;
  }

  #[Route(path: '/account/nmi-saved-cards', name: 'frontend.account.nmi-saved-cards.page', methods: ['GET'])]
  public function index(SalesChannelContext $context): Response
  {
    $customerId = $context->getCustomer()?->getId();

    if (!$customerId) {
      return $this->redirectToRoute('frontend.account.login.page');
    }

    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('customerId', $customerId));

    $savedCards = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->getElements();
    $defaultBilling = null;

    if (count($savedCards) > 0) {
      $defaultBilling = json_decode($savedCards[key($savedCards)]->defaultBilling, true)[0] ?? null;
    }

    $formattedCards = [];

    foreach ($savedCards as $card) {
      $billingData = json_decode($card->getBillingId(), true);
      if (is_array($billingData)) {
        foreach ($billingData as $billingEntry) {
          $formattedCards[] = [
            'vaultedCustomerId' => $card->getVaultedCustomerId(),
            'billingId' => $billingEntry['billingId'],
            'cardType' => $billingEntry['cardType'],
            'lastDigits' => $billingEntry['lastDigits'] ?? 'XXXX',
            'firstName' => $billingEntry['firstName'] ?? 'Unknown',
            'lastName' => $billingEntry['lastName'] ?? 'Unknown',
            'ccexp' => $billingEntry['ccexp'] ?? '',
            'name' => $billingEntry['firstName'] ?? 'Unknown',
            'created_at' => $card->getCreatedAt(),
            'isDefault' => ($defaultBilling && $defaultBilling['billingId'] == $billingEntry['billingId']) // Compare to set default
          ];
        }
      }
    }

    return $this->renderStorefront('@Storefront/storefront/page/account/nmi-saved-cards.html.twig', [
      'savedCards' => $formattedCards,
      'defaultBilling' => $defaultBilling
    ]);
  }

  #[Route(path: '/account/delete-billing-id', name: 'frontend.account.delete-billing-id', methods: ['POST'])]
  public function deleteBilling(Request $request, SalesChannelContext $context): Response
  {
    $data = json_decode($request->getContent(), true);

    if (!isset($data['vaulted_customer_id']) || !isset($data['billing_id'])) {
      return new JsonResponse(
        'Missing required parameters: vaulted_customer_id or billing_id.',
        Response::HTTP_BAD_REQUEST
      );
    }

    $customerId = $context->getCustomer()->getId();
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $data['vaulted_customer_id']));
    $criteria->addFilter(new EqualsFilter('customerId', $customerId));

    $vaultedCard = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->first();

    if (!$vaultedCard) {
      return new JsonResponse('Unauthorized action.', Response::HTTP_FORBIDDEN);
    }

    try {
      $this->nmiVaultedCustomerService->deleteBillingRecord(
        $data['vaulted_customer_id'],
        $data['billing_id'],
        $context
      );

      return new JsonResponse(['message' => 'Billing record deleted successfully.'], Response::HTTP_OK);

    } catch (\Exception $e) {
      return new JsonResponse(
        'Billing processing failed due to an internal error: ' . $e->getMessage(),
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }
  }

  #[Route(path: '/account/set-default-billing-id', name: 'frontend.account.set-default-billing-id', methods: ['POST'])]

  public function setDefaultBilling(Request $request, SalesChannelContext $context): Response
  {
    $data = json_decode($request->getContent(), true);

    if (!isset($data['vaulted_customer_id']) || !isset($data['billing_id'])) {
      return new JsonResponse(
        'Missing required parameters: vaulted_customer_id or billing_id.',
        Response::HTTP_BAD_REQUEST
      );
    }

    $customerId = $context->getCustomer()->getId();
    $criteria = new Criteria();
    $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $data['vaulted_customer_id']));
    $criteria->addFilter(new EqualsFilter('customerId', $customerId));

    $vaultedCard = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->first();

    if (!$vaultedCard) {
      return new JsonResponse('Unauthorized action.', Response::HTTP_FORBIDDEN);
    }

    try {
      $this->nmiVaultedCustomerService->setDefaultBilling($data['vaulted_customer_id'], $data['billing_id'], $context);

      return new JsonResponse(['message' => 'Default billing updated successfully.'], Response::HTTP_OK);
    } catch (\Exception $e) {
      return new JsonResponse(
        'Payment processing failed due to an internal error: ' . $e->getMessage(),
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }
  }

  #[Route(path: '/account/nmi-saved-cards/save', name: 'frontend.account.nmi-saved-cards.save', methods: ['POST'])]
  public function saveCard(Request $request, SalesChannelContext $context): Response
  {
    $customer = $context->getCustomer();
    if (!$customer) {
      return $this->redirectToRoute('frontend.account.login.page');
    }

    $data = [
      'customerId' => $customer->getId(),
      'cardType' => $request->request->get('cardType'),
      'vaultedCustomerId' => $request->request->get('vaultedCustomerId'),
      'createdAt' => new \DateTime(),
      'updatedAt' => new \DateTime(),
    ];

    $this->vaultedCustomerRepository->upsert([$data], $context->getContext());

    return $this->redirectToRoute('frontend.account.nmi-saved-cards.page');
  }

}