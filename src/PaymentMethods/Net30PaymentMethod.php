<?php

declare(strict_types=1);

namespace NMIPayment\PaymentMethods;

use NMIPayment\Gateways\Net30;

class Net30PaymentMethod implements PaymentMethodInterface
{
    public function getName(): string
    {
        return 'Payment Terms (NET 30)';
    }

    public function getDescription(): string
    {
        return 'Invoice payment terms for dealer accounts (NET 30).';
    }

    public function getPaymentHandler(): string
    {
        return Net30::class;
    }

    public function getTechnicalName(): string
    {
        return 'NMI-NET30';
    }
}
