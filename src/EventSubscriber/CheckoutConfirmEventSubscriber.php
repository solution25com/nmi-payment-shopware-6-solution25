<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Cart\Address\Error\ShippingAddressBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private const GATEWAY_CREDIT_CARD = 'creditCard';
    private const GATEWAY_ACH_ECHECK = 'achEcheck';

    private const CONFIG_FLOW = 'flow';
    private const CONFIG_THREE_DS = 'threeDS';

    private const SUPPORTED_GATEWAYS = [
        CreditCard::class => self::GATEWAY_CREDIT_CARD,
        AchEcheck::class => self::GATEWAY_ACH_ECHECK,
    ];

    public function __construct(
        private readonly VaultedCustomerService $vaultedCustomerService,
        private readonly NMIConfigService $configService
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
            AccountEditOrderPageLoadedEvent::class => 'addPaymentMethodFieldsForOrderEdit',
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @return void
     */
    public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $gateway = $this->resolveGateway($salesChannelContext);

        if ($gateway === null) {
            return;
        }

        $pageObject = $event->getPage();
        $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $errors = $pageObject->getCart()->getErrors();
        $shippingError = false;

        foreach ($errors as $error) {
            if ($error instanceof ShippingAddressBlockedError) {
                $shippingError = true;
                break;
            }
        }

        $this->addPaymentMethodExtension(
            new CheckoutTemplateCustomData(),
            $pageObject,
            $gateway,
            $this->configService->getConfig(self::CONFIG_FLOW, $salesChannelId),
            $amount,
            $this->configService->getConfig(self::CONFIG_THREE_DS, $salesChannelContext->getSalesChannelId()),
            $salesChannelContext->getCustomer()->getGuest(),
            $shippingError,
            $event->getContext(),
            $salesChannelContext
        );
    }

    /**
     * @param AccountEditOrderPageLoadedEvent $event
     * @return void
     */
    public function addPaymentMethodFieldsForOrderEdit(AccountEditOrderPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $gateway = $this->resolveGateway($salesChannelContext);

        if ($gateway === null) {
            return;
        }

        $pageObject = $event->getPage();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $this->addPaymentMethodExtension(
            new CheckoutTemplateCustomData(),
            $pageObject,
            $gateway,
            $this->configService->getConfig(self::CONFIG_FLOW, $salesChannelId),
            $pageObject->getOrder()->getAmountTotal(),
            $this->configService->getConfig(self::CONFIG_THREE_DS, $salesChannelContext->getSalesChannelId()),
            $salesChannelContext->getCustomer()->getGuest(),
            false,
            $event->getContext(),
            $salesChannelContext
        );
    }

    private function resolveGateway(SalesChannelContext $salesChannelContext): ?string
    {
        $handlerIdentifier = $salesChannelContext->getPaymentMethod()->getHandlerIdentifier();

        return self::SUPPORTED_GATEWAYS[$handlerIdentifier] ?? null;
    }

    private function addPaymentMethodExtension(
        CheckoutTemplateCustomData $templateVariables,
        mixed $pageObject,
        string $gateway,
        string $flow,
        float $amount,
        mixed $threeDS,
        bool $isGuest,
        bool $shippingError,
        Context $context,
        SalesChannelContext $salesChannelContext
    ): void {
        $billingAddress = $salesChannelContext
            ->getCustomer()
            ->getDefaultBillingAddress();
        $city = $billingAddress
            ? trim((string) $billingAddress->getCity())
            : '';

        $templateVariables->assign([
            'template' => $this->getTemplateForGateway($gateway),
            'threeDS' => $threeDS,
            'isGuest' => $isGuest,
            'gateway' => $gateway,
            'flow' => $flow,
            'amount' => $amount,
            'shippingError' => $shippingError,
        ]);

        $configs = $this->configService->getModeConfig($salesChannelContext->getSalesChannelId());

        if ($gateway === self::GATEWAY_CREDIT_CARD) {
            $customerId = $salesChannelContext->getCustomer()->getId();
            $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
            $vaultedCustomerId = $this->vaultedCustomerService
                ->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
            $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
            $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context, $customerId);

            $city = $salesChannelContext->getCustomer()->getDefaultBillingAddress()
                ? $salesChannelContext->getCustomer()->getDefaultBillingAddress()->getCity()
                : null;

            $nmiClass = $pageObject->getPaymentMethods()->filter(
                function (PaymentMethodEntity $paymentMethod) {
                    return $paymentMethod->getHandlerIdentifier() === CreditCard::class;
                }
            );

            $templateVariables->assign([
                'configs' => $configs,
                'paymentMethodId' => $nmiClass->first()->getId(),
                'saveCardBackend' => $isCardSaved,
                'vaultedId' => $vaultedCustomerId,
                'billingId' => $billingId,
                'cardsDropdown' => json_encode($cardsDropdown),
                'billingCity' => $city,
            ]);
        }

        if ($gateway === self::GATEWAY_ACH_ECHECK) {
            $templateVariables->assign([
                'configs' => $this->configService->getModeConfig($salesChannelContext->getSalesChannelId()),
            ]);
        }

        $pageObject->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $templateVariables);

        if (empty($configs['publicKey']) || empty($configs['checkoutKey'])) {
            $filteredPaymentMethods = $pageObject->getPaymentMethods()->filter(
                function (PaymentMethodEntity $paymentMethod) {
                    return $paymentMethod->getHandlerIdentifier() !== CreditCard::class;
                }
            );
            $pageObject->setPaymentMethods($filteredPaymentMethods);
        }
    }

    private function getTemplateForGateway(string $gateway): string
    {
        switch ($gateway) {
            case 'creditCard':
                return '@Storefront/nmi-payment/credit-card.html.twig';
            case 'achEcheck':
                return '@Storefront/nmi-payment/ach-eCheck.html.twig';
            default:
                return '';
        }
    }
}
