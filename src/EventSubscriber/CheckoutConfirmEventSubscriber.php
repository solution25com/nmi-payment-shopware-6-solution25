<?php

namespace NMIPayment\EventSubscriber;

use NMIPayment\Service\NMIConfigService;
use NMIPayment\Service\VaultedCustomerService;
use NMIPayment\Gateways\CreditCard;
use NMIPayment\Gateways\AchEcheck;
use NMIPayment\Service\NMIPaymentApiClient;
use NMIPayment\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Response;


class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private EntityRepository $customerRepository;
    private NMIPaymentApiClient $nmiPaymentApiClient;
    private VaultedCustomerService $vaultedCustomerService;
    private NMIConfigService $configService;

    public function __construct(EntityRepository $customerRepository,
                                NMIPaymentApiClient $nmiPaymentApiClient,
                                VaultedCustomerService $vaultedCustomerService,
                                NMIConfigService $configService)
    {
        $this->customerRepository = $customerRepository;
        $this->nmiPaymentApiClient = $nmiPaymentApiClient;
        $this->vaultedCustomerService = $vaultedCustomerService;
        $this->configService = $configService;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
          AccountEditOrderPageLoadedEvent::Class => 'addPaymentMethodSpecificToAccountOrder',
          CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
        ];
    }

    public function addPaymentMethodSpecificFormFields(CheckoutConfirmPageLoadedEvent $event): void
    {
      $threeDS = $this->configService->getConfig('threeDS');
      $context = $event->getContext();
      $pageObject = $event->getPage();
//      dd($pageObject->getCart());
      $amount = $pageObject->getCart()->getPrice()->getTotalPrice();
      $salesChannelContext = $event->getSalesChannelContext();
      $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();
      $isGuest = $salesChannelContext->getCustomer()->getGuest();
      $templateVariables = new CheckoutTemplateCustomData();
      if ($selectedPaymentGateway->getHandlerIdentifier() == CreditCard::class) {

        $customerId = $salesChannelContext->getCustomer()->getId();
//          $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
        $vaultedCustomerId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
        $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
        $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context,$customerId);

        $templateVariables->assign([
          'template' => '@Storefront/nmi-payment/credit-card.html.twig',
          'threeDS' => $threeDS,
          'isGuest' => $isGuest,
          'gateway' => 'creditCard',
          'saveCardBackend' => $isCardSaved,
          'vaultedId' => $vaultedCustomerId,
          'amount' => $amount,
          'billingId' => $billingId,
          'cardsDropdown' => json_encode($cardsDropdown),
//                'currency' => $currency,
        ]);
        $pageObject->addExtension(
          CheckoutTemplateCustomData::EXTENSION_NAME,
          $templateVariables
        );
      } elseif ($selectedPaymentGateway->getHandlerIdentifier() == AchEcheck::class) {
        $templateVariables->assign([
          'template' => '@Storefront/nmi-payment/ach-eCheck.html.twig',
          'isGuestLogin' => $isGuest,
          'amount' => $amount,
          'gateway' => 'achEcheck',
        ]);
        $pageObject->addExtension(
          CheckoutTemplateCustomData::EXTENSION_NAME,
          $templateVariables
        );
      }
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

  public function addPaymentMethodSpecificToAccountOrder($event): void
  {

    $pageObject = $event->getPage();
    $context = $event->getContext();
    $salesChannelContext = $event->getSalesChannelContext();
    $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();
    $templateVariables = new CheckoutTemplateCustomData();
    if ($selectedPaymentGateway->getHandlerIdentifier() == CreditCard::class) {
      $customerId = $salesChannelContext->getCustomer()->getId();
      $isCardSaved = $this->vaultedCustomerService->vaultedCustomerExist($context, $customerId);
      $vaultedCustomerId = $this->vaultedCustomerService->getVaultedCustomerIdByCustomerId($context, $customerId) ?? null;
      $billingId = $this->vaultedCustomerService->getBillingIdFromCustomerId($context, $customerId);
      $cardsDropdown = $this->vaultedCustomerService->dropdownCards($context,$customerId);


        $templateVariables->assign([
          'template' => '@Storefront/nmi-payment/credit-card.html.twig',
        'gateway' => 'creditCard',
        'saveCardBackend' => $isCardSaved,
        'vaultedId' => $vaultedCustomerId,
        'billingId' => $billingId,
        'cardsDropdown' => json_encode($cardsDropdown),
//                'currency' => $currency,
      ]);
      $pageObject->addExtension(
        CheckoutTemplateCustomData::EXTENSION_NAME,
        $templateVariables
      );
    }

  }



}