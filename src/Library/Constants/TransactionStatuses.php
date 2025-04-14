<?php

declare(strict_types=1);

namespace NMIPayment\Library\Constants;

enum TransactionStatuses: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAIL = 'fail';
    case REFUND = 'refund';
    case CANCELLED = 'cancelled';
    case AUTHORIZED = 'authorized';
    case UNCONFIRMED = 'unconfirmed';
}
