<?php

declare(strict_types=1);

namespace NMIPayment\PaymentMethods;

use NMIPayment\Gateways\CreditCard;

class CreditCardPaymentMethod implements PaymentMethodInterface
{
    public function getName(): string
    {
        return 'NMI Credit Card';
    }

    public function getDescription(): string
    {
        return 'NMI Credit Card Payment';
    }

    public function getPaymentHandler(): string
    {
        return CreditCard::class;
    }
}
