<?php

namespace NMIPayment\PaymentMethods;

use NMIPayment\Gateways\AchEcheck;

class ACHPaymentMethod implements PaymentMethodInterface
{

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'NMI ACH (eCheck) Payment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'NMI ACH (eCheck) Payment';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentHandler(): string
    {
        return AchEcheck::class;
    }
}