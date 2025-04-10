<?php

declare(strict_types=1);

namespace NMIPayment\Library\Constants;

enum EnvironmentUrl: string
{
    case SANDBOX = 'https://secure.nmi.com';
    case LIVE = 'https://secure.nmi.com/api/live';
}
