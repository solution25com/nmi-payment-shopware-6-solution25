<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NMIPayment\Library\Constants\EnvironmentUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class NMIPaymentApiClient extends Endpoints
{
    private readonly Client $client;

    public function __construct(private readonly NMIConfigService $configService, private readonly LoggerInterface $logger)
    {
        $mode = $this->configService->getConfig('mode');
        $isLive = 'live' === $mode;

        $baseUrl = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
        $this->client = new Client(['base_uri' => $baseUrl->value]);
    }

    public function createTransaction(array $queryParams): ?array
    {
        $apiKey = $this->configService->getPrivateKey();
        if (!$apiKey) {
            $this->logger->error('Missing API key for NMI transaction.');

            return null;
        }

        $queryParams['security_key'] = $apiKey;

        $options = [
            'headers' => ['Accept' => 'application/json'],
            'query' => $queryParams,
        ];

        $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);
        if (!$response instanceof \Psr\Http\Message\ResponseInterface) {
            return null;
        }

        parse_str($response->getBody()->getContents(), $parsedResponse);

        return $parsedResponse;
    }

    public function getVaultedCustomer(array $queryParams): ?array
    {
        $apiKey = $this->configService->getPrivateKey();
        if (!$apiKey) {
            $this->logger->error('Missing API key for NMI Vaulted Customer request.');

            return null;
        }

        $queryParams['security_key'] = $apiKey;

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'query' => $queryParams,
        ];

        $response = $this->request(self::getEndpoint(self::VAULTEDCUSTOMER), $options);
        if (!$response instanceof \Psr\Http\Message\ResponseInterface) {
            return null;
        }

        parse_str($response->getBody()->getContents(), $parsedResponse);

        return $parsedResponse;
    }

    private function request(array $endpoint, array $options): ?ResponseInterface
    {
        try {
            ['method' => $method, 'url' => $url] = $endpoint;

            return $this->client->request($method, $url, $options);
        } catch (GuzzleException) {
        }

        return null;
    }
}
