<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Binds the order-submit request to the NMI transaction this session actually created.
 *
 * The storefront relays the gateway transaction id to CreditCard::pay() / AchEcheck::pay() as a
 * form field (`nmi_transaction_id`), so a client can post any value there: a made-up id, or a
 * real transaction from another checkout. The auth routes record the id NMI returned; pay() only
 * accepts a posted id that matches it. The record is single use, so a replayed or
 * double-submitted form is refused too.
 */
final class AuthorizedTransactionSession
{
    public const SESSION_KEY = 'nmi_verified_txn_id';

    public static function remember(Request $request, mixed $transactionId): void
    {
        if (!$request->hasSession() || !\is_scalar($transactionId)) {
            return;
        }

        $transactionId = trim((string) $transactionId);
        if ($transactionId === '') {
            return;
        }

        $request->getSession()->set(self::SESSION_KEY, $transactionId);
    }

    /**
     * Consumes the recorded id and reports whether it matches the posted one.
     */
    public static function consumeMatches(Request $request, string $postedTransactionId): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $session = $request->getSession();
        $recorded = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        return \is_string($recorded)
            && $recorded !== ''
            && hash_equals($recorded, trim($postedTransactionId));
    }
}
