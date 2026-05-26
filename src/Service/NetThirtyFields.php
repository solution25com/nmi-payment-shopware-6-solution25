<?php

declare(strict_types=1);

namespace NMIPayment\Service;

final class NetThirtyFields
{
    public const DEALER_PAYMENT_TYPE = 'dealer_payment_type';
    public const DEALER_PAYMENT_TYPE_NET30 = 'net_30';

    public const PAYMENT_LINK = 'net_30_payment_link';
    public const PAYMENT_LINK_TOKEN = 'net_30_payment_link_token';
    public const PAYMENT_LINK_EXPIRES_AT = 'net_30_payment_link_expires_at';
    public const INVOICE_NUMBER = 'net_30_invoice_number';

    public const PAYMENT_COMPLETED = 'net_30_payment_completed';
    public const PAYMENT_COMPLETED_AT = 'net_30_payment_completed_at';
    public const PAYMENT_FAILED = 'net_30_payment_failed';
    public const PAYMENT_OVERDUE = 'net_30_payment_overdue';
    public const PAYMENT_OVERDUE_AT = 'net_30_payment_overdue_at';
    public const TRANSACTION_ID = 'net_30_transaction_id';
    public const PAYMENT_METHOD = 'net_30_payment_method';
    public const PAYMENT_LINK_EMAIL_SENT = 'net_30_payment_link_email_sent';

    public const ALTERNATE_DUE_DATE = 'net_30_alternate_due_date';
    public const PAYMENT_LINK_RESENT_AT = 'net_30_payment_link_resent_at';
    public const PAYMENT_LINK_RESEND_COUNT = 'net_30_payment_link_resend_count';
}
