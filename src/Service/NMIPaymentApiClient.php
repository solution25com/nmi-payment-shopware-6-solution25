<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NMIPayment\Library\Constants\EnvironmentUrl;
use Psr\Log\LoggerInterface;

class NMIPaymentApiClient extends Endpoints
{
    /**
     * Gateway call bounds. Without them a hung gateway holds a checkout request, a worker or the
     * capture sweep indefinitely; an auth can legitimately take a few seconds, so 30 s is generous.
     */
    private const GATEWAY_TIMEOUT_S = 30;
    private const GATEWAY_CONNECT_TIMEOUT_S = 5;

    /**
     * A refused-transaction void runs inside the shopper's checkout request. A slow gateway must
     * not hold the checkout: an auth that is not voided expires on its own.
     */
    private const VOID_TIMEOUT_S = 10;
    private const VOID_CONNECT_TIMEOUT_S = 3;

    private string $privateKey;
    private Client $client;
    private NMIConfigService $nmiConfigService;
    private LoggerInterface $logger;

    public function __construct(
        NMIConfigService $nmiConfigService,
        LoggerInterface $logger
    ) {
        $this->nmiConfigService = $nmiConfigService;
        $this->logger = $logger;
    }

    public function initializeForSalesChannel(string $salesChannelId = ''): void
    {
        $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
        $isLive = $mode === 'live';

        $baseUrl = EnvironmentUrl::DEFAULT_URL_PROD_AND_SANDBOX;

        $this->privateKey = $this->nmiConfigService->getConfig(
            $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
            $salesChannelId
        );

        $this->client = new Client([
            'base_uri' => $baseUrl->value,
            'timeout' => self::GATEWAY_TIMEOUT_S,
            'connect_timeout' => self::GATEWAY_CONNECT_TIMEOUT_S,
        ]);
    }

    private function assertInitialized(): void
    {
        if (!isset($this->client)) {
            throw new \RuntimeException('NMIPaymentApiClient must be initialized using initializeForSalesChannel() before use.');
        }
    }

    private function request(array $endpoint, array $options): ?\Psr\Http\Message\ResponseInterface
    {
        $this->assertInitialized();

        try {
            ['method' => $method, 'url' => $url] = $endpoint;
            return $this->client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('NMI API Request Error: ' . self::redactSecrets($e->getMessage()), [
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);
            return null;
        }
    }

    /**
     * Strips credential values (security_key, payment_token) from a string before it is logged.
     */
    public static function redactSecrets(string $message): string
    {
        return (string) preg_replace(
            '/(security_key|payment_token)=[^&\s"\']*/i',
            '$1=***REDACTED***',
            $message
        );
    }

    public function createTransaction(array $queryParams): ?array
    {
        $this->assertInitialized();

        $options = [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'form_params' => $queryParams,
        ];

        $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);

        if (!$response) {
            return null;
        }

        parse_str($response->getBody()->getContents(), $parsedResponse);
        return $parsedResponse;
    }

    /**
     * Queries NMI for a transaction by ID and returns key fields for server-side verification.
     * Returns null if the request fails or the response cannot be parsed.
     *
     * @return array{condition: string, amount: string, order_id: string}|null
     */
    public function queryTransaction(string $transactionId): ?array
    {
        $this->assertInitialized();

        $options = [
            'headers' => ['Accept' => 'application/xml'],
            'form_params' => [
                'security_key'   => $this->privateKey,
                'transaction_id' => $transactionId,
            ],
        ];

        $response = $this->request(self::getEndpoint(self::QUERY), $options);

        if ($response === null) {
            return null;
        }

        $xml = @simplexml_load_string($response->getBody()->getContents());

        if ($xml === false || !isset($xml->transaction)) {
            return null;
        }

        $traslateFromXML = $xml->transaction;

        return [
            'condition' => (string) ($traslateFromXML->condition ?? ''),
            'amount'    => (string) ($traslateFromXML->action->amount ?? ''),
            'order_id'  => (string) ($traslateFromXML->order_id ?? ''),
        ];
    }

    /**
     * Voids a transaction this checkout created but pay() refused, so a refused auth does not stay
     * on hold on the shopper's card and a refused sale does not keep their money. Best effort:
     * false on any failure; the caller still refuses the order.
     */
    public function voidTransaction(string $salesChannelId, string $transactionId, string $payment = 'creditcard'): bool
    {
        $this->initializeForSalesChannel($salesChannelId);

        $response = $this->request(self::getEndpoint(self::TRANSACTION), [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'security_key' => $this->privateKey,
                'type' => 'void',
                'transactionid' => $transactionId,
                'payment' => $payment,
            ],
            'timeout' => self::VOID_TIMEOUT_S,
            'connect_timeout' => self::VOID_CONNECT_TIMEOUT_S,
        ]);

        if ($response === null) {
            return false;
        }

        parse_str($response->getBody()->getContents(), $parsedResponse);

        $voided = ($parsedResponse['response'] ?? null) === '1';
        $this->logger->info('[void] ' . ($voided ? 'voided refused transaction' : 'void of refused transaction failed'), [
            'transactionId' => $transactionId,
            'response' => $parsedResponse['response'] ?? null,
            'responsetext' => $parsedResponse['responsetext'] ?? null,
        ]);

        return $voided;
    }

    /**
     * The billing ids (cards) a Customer Vault record holds at NMI, via the Query API with `ver=2`,
     * which lists every billing record (without it NMI returns only the first card). The card-delete
     * decision reads this instead of the local vault JSON, which can be incomplete.
     *
     *   ['resolved' => false]                    could not determine: the caller must not delete the vault
     *   ['resolved' => true, 'found' => false]   NMI has no such vault (already deleted)
     *   ['resolved' => true, 'found' => true, 'billingIds' => list<string>]
     *
     * @return array{resolved: bool, found: bool, billingIds: list<string>}
     */
    public function queryVaultBillingIds(string $customerVaultId): array
    {
        $this->assertInitialized();

        $response = $this->request(self::getEndpoint(self::VAULTEDCUSTOMER), [
            'headers' => ['Accept' => 'application/xml'],
            'form_params' => [
                'security_key' => $this->privateKey,
                'report_type' => 'customer_vault',
                'customer_vault_id' => $customerVaultId,
                'ver' => '2',
            ],
        ]);

        if ($response === null) {
            return ['resolved' => false, 'found' => false, 'billingIds' => []];
        }

        $result = self::parseVaultBillingXml($response->getBody()->getContents());
        if (!$result['resolved']) {
            $this->logger->error('NMI vault query: response did not resolve the vault', ['customer_vault_id' => $customerVaultId]);
        }

        return $result;
    }

    /**
     * Parses a `report_type=customer_vault&ver=2` response. Pure, so the fail-safe rules are unit
     * testable: not XML, a foreign root or an <error_response> is unresolved, never "no vault".
     *
     * @return array{resolved: bool, found: bool, billingIds: list<string>}
     */
    public static function parseVaultBillingXml(string $body): array
    {
        $unresolved = ['resolved' => false, 'found' => false, 'billingIds' => []];

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false || $xml->getName() !== 'nm_response' || isset($xml->error_response) || !isset($xml->customer_vault)) {
            return $unresolved;
        }

        $customer = $xml->customer_vault->customer[0] ?? null;
        if ($customer === null) {
            return ['resolved' => true, 'found' => false, 'billingIds' => []];
        }

        $billingIds = [];
        foreach ($customer->billing ?? [] as $billing) {
            $id = (string) ($billing['id'] ?? '');
            if ($id !== '') {
                $billingIds[] = $id;
            }
        }

        return ['resolved' => true, 'found' => true, 'billingIds' => $billingIds];
    }

    /**
     * A transaction's amounts at NMI via the Query API: the authorized amount and the totals of
     * successful capture/sale and refund actions. The refund paths cap a refund at collected
     * minus already refunded.
     *
     *   ['resolved' => false]                  could not determine: treat the amounts as unknown
     *   ['resolved' => true, 'found' => false] well-formed response, no such transaction
     *
     * @return array{resolved: bool, found: bool, authAmount: ?float, condition: ?string, capturedAmount: float, refundedAmount: float}
     */
    public function queryTransactionAmounts(string $transactionId): array
    {
        $this->assertInitialized();

        $response = $this->request(self::getEndpoint(self::QUERY), [
            'headers' => ['Accept' => 'application/xml'],
            'form_params' => [
                'security_key' => $this->privateKey,
                'transaction_id' => $transactionId,
            ],
        ]);

        if ($response === null) {
            return ['resolved' => false, 'found' => false, 'authAmount' => null, 'condition' => null, 'capturedAmount' => 0.0, 'refundedAmount' => 0.0];
        }

        $result = self::parseTransactionAmountsXml($response->getBody()->getContents());
        if (!$result['resolved']) {
            $this->logger->error('NMI transaction query: response did not resolve the amounts', ['transaction_id' => $transactionId]);
        }

        return $result;
    }

    /**
     * Parses a Query API response for queryTransactionAmounts(). Pure, so the fail-safe rules are
     * unit testable: not XML, a foreign root or an <error_response> (bad key, permissions,
     * throttling) is unresolved, never "no transaction".
     *
     * @return array{resolved: bool, found: bool, authAmount: ?float, condition: ?string, capturedAmount: float, refundedAmount: float}
     */
    public static function parseTransactionAmountsXml(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false || $xml->getName() !== 'nm_response' || isset($xml->error_response)) {
            return ['resolved' => false, 'found' => false, 'authAmount' => null, 'condition' => null, 'capturedAmount' => 0.0, 'refundedAmount' => 0.0];
        }

        $transactions = $xml->transaction;
        if ($transactions === null || \count($transactions) === 0) {
            return ['resolved' => true, 'found' => false, 'authAmount' => null, 'condition' => null, 'capturedAmount' => 0.0, 'refundedAmount' => 0.0];
        }

        $first = $transactions[0];
        $condition = isset($first->condition) ? (string) $first->condition : null;

        $authAmount = null;
        $capturedCents = 0;
        $refundedCents = 0;
        foreach ($first->action as $action) {
            $actionType = strtolower((string) $action->action_type);
            $amount = (string) $action->amount;
            if ((string) $action->success !== '1' || $amount === '' || !is_numeric($amount)) {
                continue;
            }

            if ($authAmount === null && \in_array($actionType, ['auth', 'sale'], true)) {
                $authAmount = (float) $amount;
            }

            // A sale collects at auth time.
            if (\in_array($actionType, ['capture', 'sale'], true)) {
                $capturedCents += (int) round((float) $amount * 100);
            } elseif ($actionType === 'refund') {
                $refundedCents += (int) round((float) $amount * 100);
            }
        }

        return [
            'resolved' => true,
            'found' => true,
            'authAmount' => $authAmount,
            'condition' => $condition,
            'capturedAmount' => $capturedCents / 100.0,
            'refundedAmount' => $refundedCents / 100.0,
        ];
    }

    public function testConnection(string $salesChannelId): bool
    {
        try {
            $this->initializeForSalesChannel($salesChannelId);

            $queryParams = [
                'security_key'      => $this->privateKey,
                'customer_vault_id' => 'nonexistent_test_id_12345',
            ];

            $this->assertInitialized();

            $options = [
                'headers' => [
                    'Accept' => 'application/xml',
                ],
                'form_params' => $queryParams,
            ];

            $endpoint = self::getEndpoint(self::VAULTEDCUSTOMER);
            $response = $this->client->request($endpoint['method'], $endpoint['url'], $options);

            $body = trim($response->getBody()->getContents());

            $xml = @simplexml_load_string($body);

            if ($xml === false) {
                return false;
            }

            if (isset($xml->error_response) && (string)$xml->error_response !== '') {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Test connection failed: ' . self::redactSecrets($e->getMessage()), [
                'exception_class' => $e::class,
            ]);
            return false;
        }
    }
}
