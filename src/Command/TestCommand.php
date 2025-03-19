<?php

namespace NMIPayment\Command;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use NMIPayment\Library\Constants\EnvironmentUrl;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'test:command',
    description: 'Test command',
)]
class TestCommand extends Command
{
    protected string $name = 'test:command';
    private EntityRepository $productRepository;
    private SystemConfigService $systemConfigService;
    private string $token;
    private string $privateKeyApi;
    private Client $client;
    private LoggerInterface $logger;
    protected const TRANSACTION = 'TRANSACTION';

    private static array $endpoints = [
        self::TRANSACTION => [
            'method' => 'POST',
            'url'    => 'api/transact.php'
        ]
    ];

    public function __construct(
        EntityRepository $productRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        string $name = null,
    ) {
        parent::__construct($name);
        $this->productRepository   = $productRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger              = $logger;
        $mode                      = $systemConfigService->get('NMIPayment.config.mode');
        $isLive                    = $mode === 'live';
        $baseUrl                   = $isLive ? EnvironmentUrl::LIVE : EnvironmentUrl::SANDBOX;
        $apiKey                    = $systemConfigService->get($isLive ? 'NMIPayment.config.apiKeyLive' : 'NMIPayment.config.apiKeySandbox');
        $apiPassword               = $systemConfigService->get($isLive ? 'NMIPayment.config.apiPasswordLive' : 'NMIPayment.config.apiPasswordSandbox');
        $this->privateKey          = $systemConfigService->get($isLive ? 'NMIPayment.config.privateKeyApi' : 'NMIPayment.config.privateKeyApi');

        $this->client = new Client(['base_uri' => $baseUrl->value]);
        $this->token  = base64_encode(trim($apiKey) . ':' . trim($apiPassword));
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Test command!");
        //        dump($this->createTransactionRecurring());
        dd($this->createTransactionRecurring());
        return Command::SUCCESS;
    }

    public function getConfig(string $configName): array|float|bool|int|string|null
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
        //        $queryParams = [
        //            'type' => 'sale',
        //            'security_key' => $this->privateKey,
        //            'ccnumber' => '4111111111111111',
        //            'ccexp' => '1220',
        //            'ccvcode' => '123',
        //            'amount' => 1889.00,
        //        ];

        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'query' => $queryParams
        ];

        $response = $this->request($this->getEndpoint(self::TRANSACTION), $options);
        parse_str($response->getBody()->getContents(), $parsedResponse);
        json_encode($parsedResponse, JSON_PRETTY_PRINT);

        return $parsedResponse;
    }

    public function createTransactionVoid(array $queryParams): ?array
    {
        //        $queryParams = [
        //            'type' => 'void',
        //            'security_key' => $this->privateKey,
        //            'amount' => $amount,
        //            'transactionid' => $transactionId
        //        ];

        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'query' => $queryParams
        ];

        $response = $this->request($this->getEndpoint(self::TRANSACTION), $options);
        parse_str($response->getBody()->getContents(), $parsedResponse);
        json_encode($parsedResponse, JSON_PRETTY_PRINT);

        return $parsedResponse;
    }

    public function createTransactionRefund(array $queryParams): ?array
    {
        //        $queryParams = [
        //            'type' => 'refund',
        //            'security_key' => $this->privateKey,
        //            'amount' => 1869.00,
        //            'transactionid' => 10086606665
        //        ];

        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'query' => $queryParams
        ];

        $response = $this->request($this->getEndpoint(self::TRANSACTION), $options);
        parse_str($response->getBody()->getContents(), $parsedResponse);
        json_encode($parsedResponse, JSON_PRETTY_PRINT);

        return $parsedResponse;
    }

    public function createTransactionRecurring(): ?array
    {
        $queryParams = [
            'security_key'  => $this->privateKey,
            'ccnumber'      => '4111111111111111',
            'ccexp'         => '1220',
            'recurring'     => 'add_subscription',
            'plan_payments' => 1,
            'plan_amount'   => 18.00,
            'day_frequency' => 30,
            'start_date'    => '20241209'
        ];

        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'query' => $queryParams
        ];

        $response = $this->request($this->getEndpoint(self::TRANSACTION), $options);
        parse_str($response->getBody()->getContents(), $parsedResponse);
        json_encode($parsedResponse, JSON_PRETTY_PRINT);

        return $parsedResponse;
    }

    public function createTransactionVault(array $queryParams): ?array
    {
        //        $queryParams = [
        //            'security_key' => $this->privateKey,
        //            'customer_vault' => 'add_customer',
        //            'ccnumber' => '4111111111111111',
        //            'ccexp' => '1234',
        //            'first_name' => 'John',
        //            'last_name' => 'Smith',
        //            'company' => 'Company Inc.',
        //            'address1' => 'Apartment 2',
        //            'address2' => '1234 Main St.',
        //            'city' => 'Chicago',
        //            'state' => 'IL',
        //            'zip' => '60193',
        //            'country' => 'US',
        //            'phone' => '+1 (847) 352 4850',
        //            'email' => 'test@example.com'
        //        ];

        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ],
            'query' => $queryParams
        ];

        $response = $this->request($this->getEndpoint(self::TRANSACTION), $options);
        parse_str($response->getBody()->getContents(), $parsedResponse);
        json_encode($parsedResponse, JSON_PRETTY_PRINT);

        return $parsedResponse;
    }

    protected static function getEndpoint(string $endpoint): array
    {
        return self::$endpoints[$endpoint];
    }
}
