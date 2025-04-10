<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Cart\Address\Error\ShippingAddressBlockedError;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;


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
   * @inheritDoc
   */
  public static function getSubscribedEvents(): array
  {
    return [
      CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
    ];
  }

  public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
  {
    $threeDS = $this->configService->getConfig('threeDS');
    $context = $event->getContext();
    $pageObject = $event->getPage();
    $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
    $salesChannelContext = $event->getSalesChannelContext();
    $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();
    $isGuest = $salesChannelContext->getCustomer()->getGuest();
    $templateVariables = new CheckoutTemplateCustomData();

    $errors = $pageObject->getCart()->getErrors();
    $shippingError = false;

    foreach ($errors as $error) {
      if ($error instanceof ShippingAddressBlockedError) {
        $shippingError = true;
        break;
      }
    }

    if ($selectedPaymentGateway->getHandlerIdentifier() == CreditCard::class) {
      $this->addPaymentMethodExtension($templateVariables, $pageObject, 'creditCard', $amount, $threeDS, $isGuest, $shippingError, $context, $salesChannelContext);
    } elseif ($selectedPaymentGateway->getHandlerIdentifier() == AchEcheck::class) {
      $this->addPaymentMethodExtension($templateVariables, $pageObject, 'achEcheck', $amount, $threeDS, $isGuest, $shippingError, $context, $salesChannelContext);
    }

    $this->filterSubscriptionPaymentMethods($pageObject, $event);
  }

  private function addPaymentMethodExtension(
                                              CheckoutTemplateCustomData $templateVariables,
                                              $pageObject,
                                              string $gateway,
                                              float $amount,
                                              $threeDS,
                                              bool $isGuest,
                                              bool $shippingError,
                                              $context,
                                              $salesChannelContext
  ): void {
    $templateVariables->assign([
      'template' => $this->getTemplateForGateway($gateway),
      'threeDS' => $threeDS,
      'isGuest' => $isGuest,
      'gateway' => $gateway,
      'amount' => $amount,
      'shippingError' => $shippingError,
    ]);

    if ($gateway === 'creditCard') {
      $customerId = $salesChannelContext->getCustomer()->getId();
      $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
      $vaultedCustomerId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
      $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
      $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context, $customerId);

      $templateVariables->assign([
        'saveCardBackend' => $isCardSaved,
        'vaultedId' => $vaultedCustomerId,
        'billingId' => $billingId,
        'cardsDropdown' => json_encode($cardsDropdown),
      ]);
    }

    $pageObject->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $templateVariables);
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

  private function filterSubscriptionPaymentMethods($pageObject, $event): void
  {
    $lineItemPayloads = [];
    $isSubscription = false;

    $card = $event->getPage()->getCart()->getLineItems()->getElements();

    foreach ($card as $lineItem) {
      if (isset($lineItem->getPayload()['isSubscription'])) {
        $isSubscription = true;
        break;
      }
    }

    if ($isSubscription) {
      $paymentMethods = $pageObject->getPaymentMethods();
      $filteredPaymentMethods = $paymentMethods->filter(function (PaymentMethodEntity $paymentMethod) {
        return $paymentMethod->getHandlerIdentifier() == CreditCard::class;
      });
      $pageObject->setPaymentMethods($filteredPaymentMethods);
    }
  }
}