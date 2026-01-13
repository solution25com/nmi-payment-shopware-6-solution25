<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NMIPayment\Library\Constants\EnvironmentUrl;
use Psr\Log\LoggerInterface;

/**
 * @property string $salesChannelId
 */
class NMIPaymentApiClient extends Endpoints
{
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
        $this->salesChannelId = $salesChannelId;
        $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
        $isLive = $mode === 'live';

        $baseUrl = EnvironmentUrl::DEFAULT_URL_PROD_AND_SANDBOX;

        $this->privateKey = $this->nmiConfigService->getConfig(
            $isLive ? 'privateKeyApiLive' : 'privateKeyApi',
            $salesChannelId
        );

        $this->client = new Client(['base_uri' => $baseUrl->value]);
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
            $this->logger->error('NMI API Request Error: ' . $e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    public function createTransaction(array $queryParams): ?array
    {
        $this->assertInitialized();

        $options = [
        'headers' => [
        'Accept' => 'application/json',
        ],
        'query' => $queryParams,
        ];

        $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);

        if (!$response) {
            return null;
        }

        parse_str($response->getBody()->getContents(), $parsedResponse);
        return $parsedResponse;
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
            $this->logger->error('Test connection failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            return false;
        }
    }
}
