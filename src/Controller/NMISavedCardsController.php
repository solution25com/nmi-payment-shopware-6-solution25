<?php

namespace NMIPayment\Controller;

use NMIPayment\Core\Content\VaultedCustomer\VaultedCustomerEntity;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIVaultedCustomerService;
use NMIPayment\Service\VaultedCustomerService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;

#[Route(defaults: ['_routeScope' => ['storefront'], '_loginRequired' => true])]
class NMISavedCardsController extends StorefrontController
{
    /** @var EntityRepository<\NMIPayment\Core\Content\VaultedCustomer\VaultedCustomerCollection> */
    private EntityRepository $vaultedCustomerRepository;
    private NMIVaultedCustomerService $nmiVaultedCustomerService;
    private NMIConfigService $configService;
    private VaultedCustomerService $vaultedCustomerService;
    private LoggerInterface $logger;

    public function __construct(
        EntityRepository $vaultedCustomerRepository,
        NMIVaultedCustomerService $nmiVaultedCustomerService,
        NMIConfigService $configService,
        VaultedCustomerService $vaultedCustomerService,
        LoggerInterface $logger
    ) {
        $this->vaultedCustomerRepository = $vaultedCustomerRepository;
        $this->nmiVaultedCustomerService = $nmiVaultedCustomerService;
        $this->configService = $configService;
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->logger = $logger;
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
        $criteria->setLimit(500);

        $savedCards = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->getEntities()->getElements();
      /** @var VaultedCustomerEntity[] $savedCards */
        $defaultBilling = $this->vaultedCustomerService->defaultCardOf($savedCards);

        $formattedCards = [];

      /** @var VaultedCustomerEntity $card */

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
                    'isDefault' => ($defaultBilling !== null && ($defaultBilling['billingId'] ?? null) === $billingEntry['billingId'])
                    ];
                }
            }
        }

        return $this->renderStorefront('@Storefront/storefront/page/account/nmi-saved-cards/index.html.twig', [
        'savedCards' => $formattedCards,
        'defaultBilling' => $defaultBilling,
        'configs' => $this->configService->getModeConfig($context->getSalesChannelId())
        ]);
    }

    #[Route(path: '/account/delete-billing-id', name: 'frontend.account.delete-billing-id', methods: ['POST'])]
    public function deleteBilling(Request $request, SalesChannelContext $context): Response
    {
        $fields = $this->requestFields($request, ['vaulted_customer_id', 'billing_id']);
        if ($fields === null) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Missing required parameters: vaulted_customer_id or billing_id.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        $vaultedCustomerId = $fields['vaulted_customer_id'];
        $billingId = $fields['billing_id'];

        $customerId = $context->getCustomer()?->getId();
        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        $vaultedCard = $this->findOwnedVault($vaultedCustomerId, $customerId, $context);
        if ($vaultedCard === null || !\in_array($billingId, VaultedCustomerService::billingIdsOf($vaultedCard->getBillingId()), true)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $this->nmiVaultedCustomerService->deleteCard($vaultedCustomerId, $billingId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Card deletion failed', [
                'customer_id' => $customerId,
                'vaulted_customer_id' => $vaultedCustomerId,
                'billing_id' => $billingId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => $this->trans('nmiPayment.savedCards.deleteFailed')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if (!$result['success']) {
            $this->logger->warning('Card deletion refused by the gateway', [
                'customer_id' => $customerId,
                'vaulted_customer_id' => $vaultedCustomerId,
                'billing_id' => $billingId,
                'whole_vault' => $result['wholeVault'],
                'gateway_message' => $result['message'],
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => $this->trans('nmiPayment.savedCards.deleteFailed')],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return new JsonResponse(['success' => true, 'message' => 'Billing record deleted successfully.'], Response::HTTP_OK);
    }

    /**
     * Deletes every vault of the logged-in customer, decided and done on the server. The client
     * used to send one vault id, so the customer's other vaults survived "delete all".
     */
    #[Route(path: '/account/nmi-saved-cards/delete-all', name: 'frontend.account.nmi-saved-cards.delete-all', methods: ['POST'])]
    public function deleteAllCards(SalesChannelContext $context): JsonResponse
    {
        $customerId = $context->getCustomer()?->getId();
        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->setLimit(50);

        $failedVaults = [];
        try {
            $vaults = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->getEntities();
            foreach ($vaults as $vault) {
                $vaultId = (string) $vault->getVaultedCustomerId();
                $result = $this->nmiVaultedCustomerService->deleteVault($vaultId, $context);
                if (!$result['success']) {
                    $failedVaults[] = $vaultId;
                    $this->logger->warning('Delete all: vault deletion refused by the gateway', [
                        'customer_id' => $customerId,
                        'vaulted_customer_id' => $vaultId,
                        'gateway_message' => $result['message'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Delete all cards failed', [
                'customer_id' => $customerId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => $this->trans('nmiPayment.savedCards.deleteAllFailed')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if ($failedVaults !== []) {
            return new JsonResponse(
                ['success' => false, 'message' => $this->trans('nmiPayment.savedCards.deleteAllFailed')],
                Response::HTTP_BAD_GATEWAY
            );
        }

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }

    #[Route(path: '/account/set-default-billing-id', name: 'frontend.account.set-default-billing-id', methods: ['POST'])]
    public function setDefaultBilling(Request $request, SalesChannelContext $context): Response
    {
        $fields = $this->requestFields($request, ['vaulted_customer_id', 'billing_id']);
        if ($fields === null) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Missing required parameters: vaulted_customer_id or billing_id.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $customerId = $context->getCustomer()?->getId();
        if ($customerId === null) {
            return new JsonResponse(['success' => false, 'message' => 'Authentication required.'], Response::HTTP_FORBIDDEN);
        }

        $vaultedCard = $this->findOwnedVault($fields['vaulted_customer_id'], $customerId, $context);
        if ($vaultedCard === null || !\in_array($fields['billing_id'], VaultedCustomerService::billingIdsOf($vaultedCard->getBillingId()), true)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized action.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->nmiVaultedCustomerService->setDefaultBilling($fields['vaulted_customer_id'], $fields['billing_id'], $context);

            return new JsonResponse(['success' => true, 'message' => 'Default billing updated successfully.'], Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Setting the default card failed', [
                'customer_id' => $customerId,
                'vaulted_customer_id' => $fields['vaulted_customer_id'],
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'message' => $this->trans('nmiPayment.savedCards.setDefaultFailed')],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * The JSON body's required fields as strings, or null when one is missing or not a scalar
     * (arrays or objects would fail the string-typed services).
     *
     * @param list<string> $required
     *
     * @return array<string, string>|null
     */
    private function requestFields(Request $request, array $required): ?array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return null;
        }

        $fields = [];
        foreach ($required as $key) {
            $value = $data[$key] ?? null;
            if (!\is_scalar($value) || (string) $value === '') {
                return null;
            }
            $fields[$key] = (string) $value;
        }

        return $fields;
    }

    private function findOwnedVault(string $vaultedCustomerId, string $customerId, SalesChannelContext $context): ?VaultedCustomerEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('vaultedCustomerId', $vaultedCustomerId));
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->setLimit(1);

        $vault = $this->vaultedCustomerRepository->search($criteria, $context->getContext())->getEntities()->first();

        return $vault instanceof VaultedCustomerEntity ? $vault : null;
    }
}
