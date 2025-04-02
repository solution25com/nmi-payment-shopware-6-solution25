<?php

namespace NMIPayment\PaymentMethods;

class PaymentMethods
{
    public const PAYMENT_METHODS = [
        CreditCardPaymentMethod::class,
        ACHPaymentMethod::class,
    ];
}
