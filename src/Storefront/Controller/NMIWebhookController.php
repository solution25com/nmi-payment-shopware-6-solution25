<?php

declare(strict_types=1);

namespace NMIPayment\Storefront\Controller;

use NMIPayment\Service\NMIWebhookService;
use Psr\Log\LoggerInterface;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class NMIWebhookController extends StorefrontController
{
    private NMIWebhookService $webhookService;
    private LoggerInterface $logger;

    public function __construct(
        NMIWebhookService $webhookService,
        LoggerInterface $logger
    ) {
        $this->webhookService = $webhookService;
        $this->logger = $logger;
    }

    #[Route(path: '/nmi-webhooks', name: 'frontend.nmi.webhooks', methods: ['POST'], defaults: ['csrf_protected' => false])]
    public function handleWebhook(Request $request): Response
    {
        try {
            $webhookBody = $request->getContent();
            $signatureHeader = $request->headers->get('Webhook-Signature');

            if (!$this->webhookService->verifySignature($webhookBody, $signatureHeader)) {
                $this->logger->warning('NMI Webhook signature verification failed', [
                    'ip' => $request->getClientIp(),
                ]);
                return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
            }

            $webhookData = json_decode($webhookBody, true);
            $this->logger->info('NMI Webhook received', $webhookData);
            
            if (!$webhookData) {
                $this->logger->error('NMI Webhook: Invalid JSON payload');
                return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
            }

            $this->webhookService->processWebhook($webhookData);

            return new Response('OK', Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('NMI Webhook processing error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new Response('Internal error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}


