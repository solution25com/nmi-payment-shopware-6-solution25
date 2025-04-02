<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly VaultedCustomerService $vaultedCustomerService, private readonly NMIConfigService $configService) {}

    public static function getSubscribedEvents(): array
    {
        return [
            AccountEditOrderPageLoadedEvent::class => 'addPaymentMethodSpecificToAccountOrder',
            CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
        ];
    }

    public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        $context = $event->getContext();
        $pageObject = $event->getPage();
        $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
        $isGuest = $salesChannelContext->getCustomer()->getGuest();
        $this->configService->getConfig('threeDS');

        $this->handlePaymentMethod($paymentMethod, $context, $salesChannelContext, $amount, $isGuest, $pageObject);

        if ($this->cartHasSubscription($event)) {
            $this->filterPaymentMethodsForSubscription($pageObject);
        }
    }

    public function addPaymentMethodSpecificToAccountOrder(AccountEditOrderPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        $context = $event->getContext();
        $pageObject = $event->getPage();
        $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
        $isGuest = $salesChannelContext->getCustomer()->getGuest();

        $this->handlePaymentMethod($paymentMethod, $context, $salesChannelContext, $amount, $isGuest, $pageObject);
    }

    private function handlePaymentMethod(
        PaymentMethodEntity $paymentMethod,
        Context $context,
        SalesChannelContext $salesChannelContext,
        float $amount,
        bool $isGuest,
        $pageObject
    ): void {
        $templateVariables = new CheckoutTemplateCustomData();

        switch ($paymentMethod->getHandlerIdentifier()) {
            case CreditCard::class:
                $customerId = $salesChannelContext->getCustomer()->getId();
                $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
                $vaultedCustomerId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
                $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
                $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context, $customerId);

                $templateVariables->assign([
                    'template' => '@Storefront/nmi-payment/credit-card.html.twig',
                    'threeDS' => $this->configService->getConfig('threeDS'),
                    'isGuest' => $isGuest,
                    'gateway' => 'creditCard',
                    'saveCardBackend' => $isCardSaved,
                    'vaultedId' => $vaultedCustomerId,
                    'amount' => $amount,
                    'billingId' => $billingId,
                    'cardsDropdown' => json_encode($cardsDropdown),
                ]);

                break;

            case AchEcheck::class:
                $templateVariables->assign([
                    'template' => '@Storefront/nmi-payment/ach-eCheck.html.twig',
                    'isGuestLogin' => $isGuest,
                    'amount' => $amount,
                    'gateway' => 'achEcheck',
                ]);

                break;
        }

        $pageObject->addExtension(
            CheckoutTemplateCustomData::EXTENSION_NAME,
            $templateVariables
        );
    }

    private function cartHasSubscription(CheckoutConfirmPageLoadedEvent $event): bool
    {
        $cartItems = $event->getPage()->getCart()->getLineItems()->getElements();

        foreach ($cartItems as $lineItem) {
            if (!empty($lineItem->getPayload()['isSubscription'])) {
                return true;
            }
        }

        return false;
    }

    private function filterPaymentMethodsForSubscription($pageObject): void
    {
        $paymentMethods = $pageObject->getPaymentMethods();
        $filteredPaymentMethods = $paymentMethods->filter(fn (PaymentMethodEntity $paymentMethod): bool => CreditCard::class === $paymentMethod->getHandlerIdentifier());

        $pageObject->setPaymentMethods($filteredPaymentMethods);
    }
}
