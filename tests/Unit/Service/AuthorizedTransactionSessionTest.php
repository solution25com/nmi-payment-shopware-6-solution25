<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Service\AuthorizedTransactionSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(AuthorizedTransactionSession::class)]
class AuthorizedTransactionSessionTest extends TestCase
{
    public function testRecordedIdMatchesOnce(): void
    {
        $request = $this->requestWithSession();
        AuthorizedTransactionSession::remember($request, '10001');

        self::assertTrue(AuthorizedTransactionSession::consumeMatches($request, ' 10001 '));
        self::assertFalse(AuthorizedTransactionSession::consumeMatches($request, '10001'), 'a replayed form must be refused');
    }

    public function testAnotherIdIsRefusedAndConsumesTheRecord(): void
    {
        $request = $this->requestWithSession();
        AuthorizedTransactionSession::remember($request, '10001');

        self::assertFalse(AuthorizedTransactionSession::consumeMatches($request, '99999'));
        self::assertFalse(AuthorizedTransactionSession::consumeMatches($request, '10001'));
    }

    public function testNothingRecordedIsRefused(): void
    {
        self::assertFalse(AuthorizedTransactionSession::consumeMatches($this->requestWithSession(), '10001'));
        self::assertFalse(AuthorizedTransactionSession::consumeMatches(new Request(), '10001'));
    }

    public function testEmptyOrNonScalarIdsAreNotRecorded(): void
    {
        $request = $this->requestWithSession();
        AuthorizedTransactionSession::remember($request, '  ');
        AuthorizedTransactionSession::remember($request, ['10001']);

        self::assertFalse($request->getSession()->has(AuthorizedTransactionSession::SESSION_KEY));
    }

    private function requestWithSession(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
