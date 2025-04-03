<?php

declare(strict_types=1);

namespace NMIPayment\Storefront\Controller;

use NMIPayment\Service\NMIConfigService;
use S25Subscription\Checkout\Cart\Event\S25SubscriptionPlacedEvent;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController
{
    private NMIConfigService $nmiConfigService;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;

    public function __construct(
        NMIConfigService $configService,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->nmiConfigService = $configService;
        $this->eventDispatcher  = $eventDispatcher;
        $this->logger           = $logger;
    }

    #[Route(path: '/webhook', name: 'api.webhook', methods: ['POST'])]
    public function webhook(Request $request, Context $context): JsonResponse
    {
        $rawData   = $request->getContent();
        $sigHeader = $request->headers->get('Webhook-Signature');

        if (!$sigHeader) {
            return new JsonResponse(['error' => 'Signature header missing'], 400);
        }
        if (preg_match('/t=(.*),s=(.*)/', $sigHeader, $matches)) {
            $nonce     = $matches[1];
            $signature = $matches[2];
        } else {
            return new JsonResponse(['error' => 'Unrecognized webhook signature format'], 400);
        }

        if (!$this->verifySignature($rawData, $nonce, $signature)) {
            return new JsonResponse(['error' => 'Invalid signature'], 400);
        }

        $this->logger->info('message from webhook waiting for ACU: ' . $rawData);

        $webhookData = json_decode($rawData, true);
        //    $order_id = $webhookData['event_body']['order_id'] ?? '';
        //    $this->eventDispatcher->dispatch(new S25SubscriptionPlacedEvent($order_id, Context::createDefaultContext()));
        //    $this->logger->info('message dispatch: fire');

        return new JsonResponse(['status' => true], 200);
    }
    private function verifySignature(string $webhookBody, string $nonce, string $sig): bool
    {
        $computedSig = hash_hmac('sha256', $nonce . '.' . $webhookBody, $this->nmiConfigService->getConfig('signingKey'));
        return hash_equals($computedSig, $sig);
    }
}

