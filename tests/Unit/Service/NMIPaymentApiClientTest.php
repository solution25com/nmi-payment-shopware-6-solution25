<?php

declare(strict_types=1);

namespace NMIPayment\Tests\Unit\Service;

use NMIPayment\Service\NMIPaymentApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NMIPaymentApiClient::class)]
class NMIPaymentApiClientTest extends TestCase
{
    public function testSecretsAreRedactedFromLoggedMessages(): void
    {
        $message = 'Client error: `POST https://secure.nmi.com/api/transact.php?security_key=abc123&payment_token=tok_9&amount=10` resulted in';

        $redacted = NMIPaymentApiClient::redactSecrets($message);

        self::assertStringNotContainsString('abc123', $redacted);
        self::assertStringNotContainsString('tok_9', $redacted);
        self::assertStringContainsString('amount=10', $redacted);
    }

    public function testVaultBillingIdsAreListed(): void
    {
        $xml = '<nm_response><customer_vault><customer id="v1"><billing id="b1"/><billing id="b2"/></customer></customer_vault></nm_response>';

        self::assertSame(
            ['resolved' => true, 'found' => true, 'billingIds' => ['b1', 'b2']],
            NMIPaymentApiClient::parseVaultBillingXml($xml)
        );
    }

    public function testDeletedVaultIsResolvedAsNotFound(): void
    {
        self::assertSame(
            ['resolved' => true, 'found' => false, 'billingIds' => []],
            NMIPaymentApiClient::parseVaultBillingXml('<nm_response><customer_vault/></nm_response>')
        );
    }

    public function testErrorResponsesNeverReadAsMissingVault(): void
    {
        foreach (['<nm_response><error_response>Invalid key</error_response></nm_response>', 'not xml', '<other/>'] as $body) {
            self::assertFalse(NMIPaymentApiClient::parseVaultBillingXml($body)['resolved'], $body);
        }
    }

    public function testTransactionAmountsSumSuccessfulActionsOnly(): void
    {
        $xml = <<<XML
<nm_response><transaction><condition>complete</condition>
  <action><action_type>auth</action_type><amount>100.00</amount><success>1</success></action>
  <action><action_type>capture</action_type><amount>80.00</amount><success>1</success></action>
  <action><action_type>refund</action_type><amount>20.00</amount><success>1</success></action>
  <action><action_type>refund</action_type><amount>50.00</amount><success>0</success></action>
</transaction></nm_response>
XML;

        $result = NMIPaymentApiClient::parseTransactionAmountsXml($xml);

        self::assertTrue($result['resolved']);
        self::assertTrue($result['found']);
        self::assertSame(100.0, $result['authAmount']);
        self::assertSame(80.0, $result['capturedAmount']);
        self::assertSame(20.0, $result['refundedAmount']);
        self::assertSame('complete', $result['condition']);
    }

    public function testSaleCountsAsCollected(): void
    {
        $xml = '<nm_response><transaction><action><action_type>sale</action_type><amount>42.50</amount><success>1</success></action></transaction></nm_response>';

        $result = NMIPaymentApiClient::parseTransactionAmountsXml($xml);

        self::assertSame(42.5, $result['authAmount']);
        self::assertSame(42.5, $result['capturedAmount']);
    }

    public function testAmountsErrorResponseIsUnresolved(): void
    {
        self::assertFalse(NMIPaymentApiClient::parseTransactionAmountsXml('<nm_response><error_response>x</error_response></nm_response>')['resolved']);
        self::assertSame(
            ['resolved' => true, 'found' => false],
            array_intersect_key(NMIPaymentApiClient::parseTransactionAmountsXml('<nm_response></nm_response>'), ['resolved' => 0, 'found' => 0])
        );
    }
}
