<?php

declare(strict_types=1);

namespace NMIPayment\EventSubscriber;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Cart\Address\Error\ShippingAddressBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private VaultedCustomerService $vaultedCustomerService;

    private NMIConfigService $configService;

    public function __construct(VaultedCustomerService $vaultedCustomerService, NMIConfigService $configService)
    {
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->configService = $configService;
    }

  /**
   * {@inheritDoc}
   */
    public static function getSubscribedEvents(): array
    {
        return [
        CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
        ];
    }

    public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
    {

        $threeDS = $this->configService->getConfig('threeDS', $event->getSalesChannelContext()->getSalesChannelId());
        $context = $event->getContext();
        $pageObject = $event->getPage();
        $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
        $salesChannelContext = $event->getSalesChannelContext();
        $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();
        $isGuest = $salesChannelContext->getCustomer()->getGuest();
        $templateVariables = new CheckoutTemplateCustomData();
        $flow = $this->configService->getConfig('flow', $salesChannelContext->getSalesChannel()->getId());

        $errors = $pageObject->getCart()->getErrors();
        $shippingError = false;

        foreach ($errors as $error) {
            if ($error instanceof ShippingAddressBlockedError) {
                $shippingError = true;
                break;
            }
        }

        if ($selectedPaymentGateway->getHandlerIdentifier() === CreditCard::class) {
            $this->addPaymentMethodExtension($templateVariables, $pageObject, 'creditCard', $flow, $amount, $threeDS, $isGuest, $shippingError, $context, $salesChannelContext);
        }

        if ($selectedPaymentGateway->getHandlerIdentifier() === AchEcheck::class) {
            $this->addPaymentMethodExtension($templateVariables, $pageObject, 'achEcheck', $flow, $amount, $threeDS, $isGuest, $shippingError, $context, $salesChannelContext);
        }
    }

    private function addPaymentMethodExtension(
        CheckoutTemplateCustomData $templateVariables,
        $pageObject,
        string $gateway,
        string $flow,
        float $amount,
        $threeDS,
        bool $isGuest,
        bool $shippingError,
        $context,
        SalesChannelContext $salesChannelContext
    ): void {

        $billingAddress = $salesChannelContext
        ->getCustomer()
        ->getDefaultBillingAddress();
        $city = $billingAddress
        ? trim((string)$billingAddress->getCity())
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

        if ($gateway === 'creditCard') {
            $customerId = $salesChannelContext->getCustomer()->getId();
            $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
            $vaultedCustomerId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
            $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
            $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context, $customerId);

            $city = $salesChannelContext->getCustomer()->getDefaultBillingAddress()
            ? $salesChannelContext->getCustomer()->getDefaultBillingAddress()->getCity()
            : null;

            $nmiClass = $pageObject->getPaymentMethods()->filter(function (PaymentMethodEntity $paymentMethod) {
                return $paymentMethod->getHandlerIdentifier() === CreditCard::class;
            });

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

        if ($gateway === 'achEcheck') {
            $templateVariables->assign([
                'configs' => $this->configService->getModeConfig($salesChannelContext->getSalesChannelId()),
            ]);
        }

        $pageObject->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $templateVariables);

        if (empty($configs['publicKey']) || empty($configs['checkoutKey'])) {
            $filteredPaymentMethods = $pageObject->getPaymentMethods()->filter(function (PaymentMethodEntity $paymentMethod) {
                return $paymentMethod->getHandlerIdentifier() !== CreditCard::class;
            });
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
