<?php

declare(strict_types=1);

namespace NMIPayment\Library\Constants;

enum TransactionEvents: string
{
    case SALE_SUCCESS = 'transaction.sale.success';
    case SALE_FAILURE = 'transaction.sale.failure';
    case SALE_UNKNOWN = 'transaction.sale.unknown';
    
    case AUTH_SUCCESS = 'transaction.auth.success';
    case AUTH_FAILURE = 'transaction.auth.failure';
    case AUTH_UNKNOWN = 'transaction.auth.unknown';
    
    case CAPTURE_SUCCESS = 'transaction.capture.success';
    case CAPTURE_FAILURE = 'transaction.capture.failure';
    case CAPTURE_UNKNOWN = 'transaction.capture.unknown';
    
    case VOID_SUCCESS = 'transaction.void.success';
    case VOID_FAILURE = 'transaction.void.failure';
    case VOID_UNKNOWN = 'transaction.void.unknown';
    
    case REFUND_SUCCESS = 'transaction.refund.success';
    case REFUND_FAILURE = 'transaction.refund.failure';
    case REFUND_UNKNOWN = 'transaction.refund.unknown';
    
    case CREDIT_SUCCESS = 'transaction.credit.success';
    case CREDIT_FAILURE = 'transaction.credit.failure';
    case CREDIT_UNKNOWN = 'transaction.credit.unknown';
    
    case VALIDATE_SUCCESS = 'transaction.validate.success';
    case VALIDATE_FAILURE = 'transaction.validate.failure';
    case VALIDATE_UNKNOWN = 'transaction.validate.unknown';

    public static function isVoidEvent(string $eventType): bool
    {
        return in_array($eventType, [
            self::VOID_SUCCESS->value,
            self::VOID_FAILURE->value,
            self::VOID_UNKNOWN->value,
        ], true);
    }

    public static function isRefundEvent(string $eventType): bool
    {
        return in_array($eventType, [
            self::REFUND_SUCCESS->value,
            self::REFUND_FAILURE->value,
            self::REFUND_UNKNOWN->value,
        ], true);
    }

    public static function isSuccessfulEvent(string $eventType): bool
    {
        return str_ends_with($eventType, '.success');
    }
}

