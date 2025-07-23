<?php

declare(strict_types=1);

namespace NMIPayment\Library\Constants;

enum EnvironmentUrl: string
{
    case DEFAULT_URL_PROD_AND_SANDBOX = 'https://secure.nmi.com';
}
