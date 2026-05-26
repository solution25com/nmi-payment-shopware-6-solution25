<?php

declare(strict_types=1);

namespace NMIPayment\Controller;

use NMIPayment\Installer\OrderStateInstaller;
use NMIPayment\Service\NetThirtyBulkPaymentService;
use NMIPayment\Service\NetThirtyFields;
use NMIPayment\Service\NetThirtyOrderAuthorizationService;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\NMIVaultedCustomerService;
use NMIPayment\Service\VaultedCustomerService;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront'], '_loginRequired' => true])]
class AccountInvoicesController extends StorefrontController
{
    private const CONFIG_KEY_DISABLE_CC_PAYMENT = 'NMIPayment.config.disableCreditCardPayment';

    public function __construct(
        private readonly AccountOrderPageLoader $orderPageLoader,
        private readonly EntityRepository $orderRepository,
        private readonly NetThirtyBulkPaymentService $bulkPaymentService,
        private readonly VaultedCustomerService $vaultedCustomerService,
        private readonly NMIConfigService $nmiConfigService,
        private readonly SystemConfigService $systemConfigService,
        private readonly NetThirtyOrderAuthorizationService $authorizationService,
        private readonly NMIVaultedCustomerService $nmiVaultedCustomerService
    ) {
    }

    #[Route(
        path: '/account/invoices',
        name: 'frontend.account.invoices.page',
        methods: ['GET']
    )]
    public function invoicesPage(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->orderPageLoader->load($request, $context);

        $filter = $request->query->get('filter', 'all');
        $originalOrders = $page->getOrders();
        $invoiceOrders = [];

        foreach ($originalOrders as $order) {
            $customFields = $order->getCustomFields() ?? [];

            if (($customFields[NetThirtyFields::DEALER_PAYMENT_TYPE] ?? null) !== NetThirtyFields::DEALER_PAYMENT_TYPE_NET30) {
                continue;
            }

            if ($filter === 'unpaid') {
                $state = $order->getTransactions()?->first()?->getStateMachineState()?->getTechnicalName();

                if (
                    $state !== OrderStateInstaller::STATE_TECHNICAL_NAME
                    && $state !== OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
                ) {
                    continue;
                }
            }

            $invoiceOrders[] = $order;
        }

        $filteredCollection = new OrderCollection($invoiceOrders);
        $storefrontResult = new EntitySearchResult(
            OrderEntity::class,
            count($invoiceOrders),
            $filteredCollection,
            $originalOrders->getAggregations(),
            $originalOrders->getCriteria(),
            $context->getContext()
        );

        $page->setOrders($storefrontResult);

        $net30Error = $request->query->get('net30Error');
        if ($net30Error) {
            $this->addFlash('danger', $net30Error);
        }

        return $this->renderStorefront('@NMIPayment/storefront/page/account/invoices.html.twig', [
            'page' => $page,
            'filter' => $filter,
        ]);
    }

    #[Route(
        path: '/account/invoices/bulk-payment',
        name: 'frontend.account.invoices.bulk-payment',
        methods: ['GET', 'POST']
    )]
    public function bulkPaymentForm(Request $request, SalesChannelContext $context): Response
    {
        if ($request->isMethod('POST') && !$request->isXmlHttpRequest()) {
            $orderIds = $request->request->all('orderIds') ?: $request->query->all('orderIds');
            if (!empty($orderIds)) {
                $queryParams = [];
                foreach ($orderIds as $id) {
                    $queryParams['orderIds'][] = $id;
                }
                return $this->redirectToRoute('frontend.account.invoices.bulk-payment', $queryParams);
            }
            return $this->redirectToRoute('frontend.account.invoices.page');
        }

        $orderIds = $request->query->all('orderIds');

        if (empty($orderIds)) {
            $this->addFlash('danger', 'No orders selected for payment.');
            return $this->redirectToRoute('frontend.account.invoices.page');
        }

        if (!is_array($orderIds)) {
            $orderIds = [$orderIds];
        }

        $customerId = $context->getCustomer()?->getId();
        if ($customerId === null) {
            return $this->redirectToRoute('frontend.account.login.page');
        }

        $hasSavedCards = $this->vaultedCustomerService->vaultedCustomerExist($context->getContext(), $customerId);

        $criteria = new Criteria($orderIds);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('billingAddress');

        $orders = $this->orderRepository->search($criteria, $context->getContext())->getEntities();
        $orders = $orders->filter(fn (OrderEntity $order) => $this->authorizationService->isOrderOwnedByCustomer($order, $customerId));

        $totalAmount = 0;
        $validOrders = [];
        foreach ($orders as $order) {
            $state = $order->getTransactions()->first()?->getStateMachineState()?->getTechnicalName();
            if (
                $state === OrderStateInstaller::STATE_TECHNICAL_NAME
                || $state === OrderStateInstaller::STATE_OVERDUE_TECHNICAL_NAME
            ) {
                $validOrders[] = $order;
                $totalAmount += $order->getPrice()->getTotalPrice();
            }
        }

        if (empty($validOrders)) {
            $this->addFlash('danger', 'No valid unpaid orders found.');
            return $this->redirectToRoute('frontend.account.invoices.page');
        }

        $savedCards = [];
        if ($hasSavedCards) {
            $savedCards = $this->vaultedCustomerService->dropdownCards($context->getContext(), $customerId);
        }

        $salesChannelId = $context->getSalesChannel()->getId();
        $nmiConfigs = $this->nmiConfigService->getModeConfig($salesChannelId);
        $disableCreditCardPayment = $this->systemConfigService->getBool(
            self::CONFIG_KEY_DISABLE_CC_PAYMENT,
            $salesChannelId
        ) ?? false;
        $enableCreditCardPayment = !$disableCreditCardPayment;

        return $this->renderStorefront('@NMIPayment/storefront/page/account/bulk-payment-form.html.twig', [
            'orders' => $validOrders,
            'totalAmount' => $totalAmount,
            'orderIds' => array_map(fn ($order) => $order->getId(), $validOrders),
            'savedCards' => $savedCards,
            'hasSavedCards' => $hasSavedCards,
            'nmiConfigs' => $nmiConfigs,
            'enableCreditCardPayment' => $enableCreditCardPayment,
        ]);
    }

    #[Route(
        path: '/account/invoices/bulk-payment/process-nmi',
        name: 'frontend.account.invoices.bulk-payment.process-nmi',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function processNmiBulkPayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        if (!$this->bulkPaymentService->hasProcessor()) {
            $unavailableMsg = 'Bulk payment processing is currently unavailable.';
            return new JsonResponse([
                'success' => false,
                'message' => $unavailableMsg,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $unavailableMsg]),
            ], 503);
        }

        $paymentData = $data['paymentData'] ?? [];
        $orderIds = $data['orderIds'] ?? [];
        if (!is_array($orderIds)) {
            $orderIds = [];
        }

        $customerVaultId = $paymentData['customer_vault_id'] ?? null;
        $billingId = $paymentData['billing_id'] ?? null;
        $customerId = $context->getCustomer()?->getId();

        if ($customerId === null || empty($customerVaultId) || empty($billingId) || empty($orderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing required payment data or order IDs'], 400);
        }

        if (!$this->authorizationService->isAllowedVaultProfile($customerId, (string) $customerVaultId, (string) $billingId, $context)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid vaulted payment profile.'], 403);
        }

        $validatedOrderIds = $this->authorizationService->filterOwnedUnpaidNet30OrderIds($orderIds, $customerId, $context);
        if (empty($validatedOrderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No valid customer-owned unpaid NET 30 orders found.'], 403);
        }

        try {
            $results = $this->bulkPaymentService->processBulkPaymentWithSavedCard(
                $customerVaultId,
                $billingId,
                $validatedOrderIds,
                $context->getContext()
            );

            $failureCount = count(array_filter($results, fn ($r) => !($r['success'] ?? false)));

            if ($failureCount > 0) {
                $errorMessages = [];
                foreach ($results as $result) {
                    if (!($result['success'] ?? false) && isset($result['error'])) {
                        $errorMessages[] = $result['error'];
                    }
                }
                $errorMessage = 'Some orders failed to process: ' . implode('; ', array_unique($errorMessages));

                return new JsonResponse([
                    'success' => false,
                    'message' => $errorMessage,
                    'results' => $results,
                    'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $errorMessage]),
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'All orders processed successfully',
                'results' => $results,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $e->getMessage()]),
            ], 500);
        }
    }

    #[Route(
        path: '/account/invoices/bulk-payment/process-nmi-token',
        name: 'frontend.account.invoices.bulk-payment.process-nmi-token',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function processNmiBulkPaymentWithToken(Request $request, SalesChannelContext $context): JsonResponse
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        $token = $data['token'] ?? null;
        $orderIds = $data['orderIds'] ?? [];
        $customerId = $context->getCustomer()?->getId();

        if (!$token || !$customerId || empty($orderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing required data'], 400);
        }

        if (!is_array($orderIds)) {
            $orderIds = [$orderIds];
        }

        if (!$this->bulkPaymentService->hasProcessor()) {
            $unavailableMsg = 'Bulk payment processing is currently unavailable.';
            return new JsonResponse([
                'success' => false,
                'message' => $unavailableMsg,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $unavailableMsg]),
            ], 503);
        }

        $cardData = [
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name'] ?? '',
            'card_type'  => $data['card_type'] ?? null,
            'ccnumber'   => $data['ccnumber'] ?? null,
            'ccexp'      => $data['ccexp'] ?? null,
        ];

        $vaultResult = $this->nmiVaultedCustomerService->saveNewCardToVault($token, $cardData, $context);
        if (!($vaultResult['success'] ?? false)) {
            return new JsonResponse([
                'success' => false,
                'message' => $vaultResult['message'] ?? 'Failed to save card to vault',
            ], 400);
        }

        $customerVaultId = $vaultResult['customer_vault_id'];
        $billingId = $vaultResult['billing_id'];

        $validatedOrderIds = $this->authorizationService->filterOwnedUnpaidNet30OrderIds($orderIds, $customerId, $context);
        if (empty($validatedOrderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No valid customer-owned unpaid NET 30 orders found.'], 403);
        }

        try {
            $results = $this->bulkPaymentService->processBulkPaymentWithSavedCard(
                $customerVaultId,
                $billingId,
                $validatedOrderIds,
                $context->getContext()
            );

            $failureCount = count(array_filter($results, fn ($r) => !($r['success'] ?? false)));

            if ($failureCount > 0) {
                $errorMessages = [];
                foreach ($results as $result) {
                    if (!($result['success'] ?? false) && isset($result['error'])) {
                        $errorMessages[] = $result['error'];
                    }
                }
                $errorMessage = 'Some orders failed to process: ' . implode('; ', array_unique($errorMessages));

                return new JsonResponse([
                    'success'     => false,
                    'message'     => $errorMessage,
                    'results'     => $results,
                    'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $errorMessage]),
                ]);
            }

            return new JsonResponse([
                'success'     => true,
                'message'     => 'All orders processed successfully',
                'results'     => $results,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success'     => false,
                'message'     => $e->getMessage(),
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $e->getMessage()]),
            ], 500);
        }
    }

    #[Route(
        path: '/account/invoices/bulk-payment/process-ach',
        name: 'frontend.account.invoices.bulk-payment.process-ach',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function processACHBulkPayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        if (!$this->bulkPaymentService->hasProcessor()) {
            $unavailableMsg = 'Bulk payment processing is currently unavailable.';
            return new JsonResponse([
                'success' => false,
                'message' => $unavailableMsg,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $unavailableMsg]),
            ], 503);
        }

        $paymentData = $data['paymentData'] ?? [];
        $orderIds = $data['orderIds'] ?? [];
        $customerId = $context->getCustomer()?->getId();
        if (!is_array($orderIds)) {
            $orderIds = [];
        }

        if ($customerId === null || empty($paymentData) || empty($orderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing required payment data or order IDs'], 400);
        }

        $validatedOrderIds = $this->authorizationService->filterOwnedUnpaidNet30OrderIds($orderIds, $customerId, $context);
        if (empty($validatedOrderIds)) {
            return new JsonResponse(['success' => false, 'message' => 'No valid customer-owned unpaid NET 30 orders found.'], 403);
        }

        try {
            $results = $this->bulkPaymentService->processBulkPaymentWithACH(
                $paymentData,
                $validatedOrderIds,
                $context
            );

            $failureCount = count(array_filter($results, fn ($r) => !($r['success'] ?? false)));

            if ($failureCount > 0) {
                $errorMessages = [];
                foreach ($results as $result) {
                    if (!($result['success'] ?? false) && isset($result['error'])) {
                        $errorMessages[] = $result['error'];
                    }
                }
                $errorMessage = 'Some orders failed to process: ' . implode('; ', array_unique($errorMessages));

                return new JsonResponse([
                    'success' => false,
                    'message' => $errorMessage,
                    'results' => $results,
                    'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $errorMessage]),
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'All orders processed successfully',
                'results' => $results,
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'redirectUrl' => $this->generateUrl('frontend.account.invoices.page', ['net30Error' => $e->getMessage()]),
            ], 500);
        }
    }
}
