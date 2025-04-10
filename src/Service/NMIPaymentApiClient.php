<?php

declare(strict_types=1);

namespace NMIPayment\Service;

use NMIPayment\Library\Constants\EnvironmentUrl;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class NMIPaymentApiClient extends Endpoints
{
  private string $privateKey;
  private Client $client;
  private SystemConfigService $systemConfigService;
  private LoggerInterface $logger;

  public function __construct(SystemConfigService $systemConfigService, LoggerInterface $logger)
  {
    $this->systemConfigService = $systemConfigService;
    $this->logger = $logger;

    $mode = $systemConfigService->get('NMIPayment.config.mode');
    $isLive = $mode === 'live';

    $baseUrl = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
    $this->privateKey = $systemConfigService->get($isLive ? 'NMIPayment.config.privateKeyApiLive' : 'NMIPayment.config.privateKeyApi');

    $this->client = new Client(['base_uri' => $baseUrl->value]);
  }

  public function getConfig(string $configName): string|bool
  {
    return $this->systemConfigService->get('NMIPayment.config.' . trim($configName));
  }

  private function request(array $endpoint, $options)
  {
    try {
      ['method' => $method, 'url' => $url] = $endpoint;
      return $this->client->request($method, $url, $options);
    } catch (GuzzleException $e) {
      $this->logger->error(dump($e));
    }
  }

  public function createTransaction(array $queryParams): ?array
  {
    $options = [
      'headers' => [
        'Accept' => 'application/json'
      ],
      'query' => $queryParams
    ];

    $response = $this->request(self::getEndpoint(self::TRANSACTION), $options);
    parse_str($response->getBody()->getContents(), $parsedResponse);
    json_encode($parsedResponse, JSON_PRETTY_PRINT);

    return $parsedResponse;
  }

  public function getVaultedCustomer(array $queryParams): ?array
  {
    $options = [
      'headers' => [
        'accept' => 'application/json',
        'Content-type' => 'application/json',
      ],
      'query' => $queryParams
    ];

    $response = $this->request(self::getEndpoint(self::VAULTEDCUSTOMER), $options);
    parse_str($response->getBody()->getContents(), $parsedResponse);
    json_encode($parsedResponse, JSON_PRETTY_PRINT);
    return $parsedResponse;
  }
}
