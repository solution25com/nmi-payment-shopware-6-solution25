<?php declare(strict_types=1);

namespace NMIPayment\PaymentMethods;

use NMIPayment\Gateways\AchEcheck;

class ACHPaymentMethod implements PaymentMethodInterface
{
    public function getName(): string
    {
        return 'NMI ACH (eCheck) Payment';
    }

    public function getDescription(): string
    {
        return 'NMI ACH (eCheck) Payment';
    }

    public function getPaymentHandler(): string
    {
        return AchEcheck::class;
    }
}
