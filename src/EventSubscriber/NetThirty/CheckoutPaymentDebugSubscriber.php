<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber\NetThirty;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CheckoutPaymentDebugSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if ($route === '' || !str_contains($route, 'checkout')) {
            return;
        }

        $exception = $event->getThrowable();
        $errorCode = method_exists($exception, 'getErrorCode')
            ? (string) $exception->getErrorCode()
            : '';

        if (
            !str_contains($exception->getMessage(), 'payment method')
            && $errorCode !== 'CHECKOUT__UNKNOWN_PAYMENT_METHOD'
            && $errorCode !== 'FRAMEWORK__INVALID_UUID'
        ) {
            return;
        }

        $paymentMethodId = $request->request->get('paymentMethodId')
            ?? $request->query->get('paymentMethodId');
        $paymentMethod = $request->request->get('paymentMethod')
            ?? $request->query->get('paymentMethod');

        $this->logger->error('[NET30-DEBUG] Checkout payment method exception', [
            'route' => $route,
            'method' => $request->getMethod(),
            'request_uri' => $request->getRequestUri(),
            'error_code' => $errorCode,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'paymentMethodId' => $paymentMethodId,
            'paymentMethodId_is_valid_uuid' => is_string($paymentMethodId) ? Uuid::isValid($paymentMethodId) : false,
            'paymentMethod' => $paymentMethod,
            'context_token' => $request->headers->get('sw-context-token'),
        ]);
    }
}
