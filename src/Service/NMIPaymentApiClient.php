<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NMIPayment\Library\Constants\EnvironmentUrl;
use Psr\Log\LoggerInterface;

class NMIPaymentApiClient extends Endpoints
{
  private string $privateKey;
  private Client $client;
  private NMIConfigService $nmiConfigService;
  private $salesChannelId;
  private LoggerInterface $logger;

  public function __construct(
    NMIConfigService $nmiConfigService,
    LoggerInterface $logger
  )
  {
    $this->nmiConfigService = $nmiConfigService;
    $this->logger = $logger;
  }

  public function initializeForSalesChannel(string $salesChannelId = ''): void
  {
    $this->salesChannelId = $salesChannelId;
    $mode = $this->nmiConfigService->getConfig('mode', $salesChannelId);
    $isLive = $mode === 'live';

    $baseUrl = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
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

  private function request(array $endpoint, array $options)
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

  public function getVaultedCustomer(array $queryParams): ?array
  {
    $this->assertInitialized();

    $options = [
      'headers' => [
        'Accept' => 'application/json',
        'Content-type' => 'application/json',
      ],
      'query' => $queryParams,
    ];

    $response = $this->request(self::getEndpoint(self::VAULTEDCUSTOMER), $options);

    if (!$response) {
      return null;
    }

    parse_str($response->getBody()->getContents(), $parsedResponse);
    return $parsedResponse;
  }
}
