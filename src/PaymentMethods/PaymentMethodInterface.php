<?php

declare(strict_types=1);

namespace NMIPayment\PaymentMethods;

interface PaymentMethodInterface
{
    /**
     * Return name of the payment method.
     */
    public function getName(): string;

    /**
     * Return the description of the payment method.
     */
    public function getDescription(): string;

    /**
     * Return the payment handler of a plugin.
     */
    public function getPaymentHandler(): string;
}
