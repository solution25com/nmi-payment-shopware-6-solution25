<?php

declare(strict_types=1);

namespace NMIPayment\Service;

abstract class Endpoints
{
    protected const TRANSACTION = 'TRANSACTION';
    protected const VAULTEDCUSTOMER = 'VAULTEDCUSTOMER';

    private static array $endpoints = [
        self::TRANSACTION => [
            'method' => 'POST',
            'url' => '/api/transact.php',
        ],
        self::VAULTEDCUSTOMER => [
            'method' => 'POST',
            'url' => '/api/query.php',
        ],
    ];

    protected static function getEndpoint(string $endpoint): array
    {
        return self::$endpoints[$endpoint];
    }
}
