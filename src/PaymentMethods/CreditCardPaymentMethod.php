<?php

namespace NMIPayment\PaymentMethods;

use NMIPayment\Gateways\CreditCard;

class CreditCardPaymentMethod implements PaymentMethodInterface
{

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'NMI Credit Card';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'NMI Credit Card Payment';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return CreditCard::class;
    }
}