<?php

declare(strict_types=1);

namespace NMIPayment\Controller\Admin;

use NMIPayment\Service\NetThirtyFields;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Package('checkout')]
#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['nmi_net30_approval:update']])]
class NetThirtyCustomerApprovalController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/api/_action/nmi/net30/customer-approval/{customerId}',
        name: 'api.nmi.net30.customer_approval.update',
        methods: ['POST']
    )]
    public function updateApproval(string $customerId, Request $request, Context $context): JsonResponse
    {
        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context)->first();
        if (!$customer instanceof CustomerEntity) {
            return new JsonResponse(['success' => false, 'message' => 'Customer not found'], Response::HTTP_NOT_FOUND);
        }

        $approved = (bool) ($request->toArray()['approved'] ?? false);
        $customFields = $customer->getCustomFields() ?? [];
        $customFields[NetThirtyFields::CUSTOMER_APPROVED] = $approved;

        $this->customerRepository->update([[
            'id' => $customerId,
            'customFields' => $customFields,
        ]], $context);

        $this->logger->info('NET30 customer approval changed', [
            'customer_id' => $customerId,
            'approved' => $approved,
        ]);

        return new JsonResponse([
            'success' => true,
            'approved' => $approved,
        ]);
    }
}
