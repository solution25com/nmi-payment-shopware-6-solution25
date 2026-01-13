<?php

declare(strict_types=1);

namespace NMIPayment\Storefront\Controller;

use NMIPayment\Service\NMIPaymentApiClient;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class TestApiConnectionController extends StorefrontController
{
    private NMIPaymentApiClient $nmiApiClient;
    private RouterInterface $router;

    public function __construct(NMIPaymentApiClient $nmiApiClient, RouterInterface $router)
    {
        $this->nmiApiClient = $nmiApiClient;
        $this->router = $router;
    }

    #[Route(path: '/api/_action/nmi-test-connection/test-connection', name: 'api.action.nmi.test-connection', methods: ['POST'])]
    public function testConnection(Request $request): Response
    {
        $salesChannelId = $request->get('salesChannelId') ?? '';
        $result = $this->nmiApiClient->testConnection($salesChannelId);

        $webhookUrl = $this->router->generate('frontend.nmi.webhooks', [], RouterInterface::ABSOLUTE_URL);

        return new JsonResponse([
            'success' => $result,
            'webhookUrl' => $webhookUrl,
            'message' => $result
                ? 'API connection successful. Use the webhook URL below to register webhooks in NMI merchant portal (Settings > Webhooks).'
                : 'API connection failed. Please check your credentials.'
        ]);
    }
}
