<?php

declare(strict_types=1);

namespace NMIPayment\Validations;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PaymentValidation
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function validateCreditCardPaymentData(?array $data): array
    {
        $constraint = new Assert\Collection([
            'token' => new Assert\NotBlank(),
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\GreaterThan(0),
            ],
            'currency' => new Assert\Optional(new Assert\Type('string')),
            'first_name' => new Assert\NotBlank(),
            'last_name' => new Assert\NotBlank(),
            'address1' => new Assert\NotBlank(),
            'city' => new Assert\NotBlank(),
            'zip' => new Assert\NotBlank(),
            'ccnumber' => new Assert\NotBlank(),
            'ccexp' => new Assert\NotBlank(),
            'card_type' => new Assert\NotBlank(),
            'cavv' => new Assert\Optional(new Assert\Type('string')),
            'xid' => new Assert\Optional(new Assert\Type('string')),
            'eci' => new Assert\Optional(new Assert\Type('string')),
            'cardholder_auth' => new Assert\Optional(new Assert\Type('string')),
            'three_ds_version' => new Assert\Optional(new Assert\Type('string')),
            'directory_server_id' => new Assert\Optional(new Assert\Type('string')),
            'cardholder_info' => new Assert\Optional(new Assert\Type('mixed')),
            'shipping' => new Assert\Optional(new Assert\Type('numeric')),
            'tax' => new Assert\Optional(new Assert\Type('numeric')),
            'ponumber' => new Assert\Optional(new Assert\Type('string')),
            'orderid' => new Assert\Optional(new Assert\Type('string')),
            'shipping_country' => new Assert\Optional(new Assert\Type('string')),
            'shipping_postal' => new Assert\Optional(new Assert\Type('string')),
            'ship_from_postal' => new Assert\Optional(new Assert\Type('string')),
            'summary_commodity_code' => new Assert\Optional(new Assert\Type('string')),
            'line_items' => new Assert\Optional(
                new Assert\All([
                    new Assert\Collection([
                        'item_product_code_' => new Assert\Optional(new Assert\Type('string')),
                        'item_description_' => new Assert\Optional(new Assert\Type('string')),
                        'item_commodity_code_' => new Assert\Optional(new Assert\Type('string')),
                        'item_unit_of_measure_' => new Assert\Optional(new Assert\Type('string')),
                        'item_unit_cost_' => [
                            new Assert\Optional(),
                            new Assert\Type(['type' => 'numeric']),
                            new Assert\GreaterThan(0),
                        ],
                        'item_quantity_' => [
                            new Assert\Optional(),
                            new Assert\Type(['type' => 'numeric']),
                            new Assert\GreaterThan(0),
                        ],
                        'item_total_amount_' => [
                            new Assert\Optional(),
                            new Assert\Type(['type' => 'numeric']),
                            new Assert\GreaterThanOrEqual(0),
                        ],
                        'item_tax_amount_' => [
                            new Assert\Optional(),
                            new Assert\Type(['type' => 'numeric']),
                            new Assert\GreaterThanOrEqual(0),
                        ],
                        'item_tax_rate_' => [
                            new Assert\Optional(),
                            new Assert\Type(['type' => 'numeric']),
                            new Assert\GreaterThanOrEqual(0),
                        ],
                    ]),
                ])
            ),

            'customer_vault' => new Assert\NotBlank(allowNull: true),
            'saveCard' => new Assert\Optional([
                new Assert\Type('bool'),
            ]),
        ]);

        return $this->validate($data, $constraint);
    }

    public function validateVaultedCustomer(?array $data): array
    {
        $constraint = new Assert\Collection([
            'token' => new Assert\Optional(),
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\GreaterThan(0),
            ],
            'customer_vault' => new Assert\Optional([new Assert\Type('string')]),
            'customer_vault_id' => new Assert\NotBlank(allowNull: true),
            'savedCard' => new Assert\Optional([
                new Assert\Type('bool'),
            ]),
            'billing_id' => new Assert\Optional([
                new Assert\Type('string'),
            ]),
        ]);

        return $this->validate($data, $constraint);
    }

    public function validateAchEcheckPaymentData(?array $data): array
    {
        $constraint = new Assert\Collection([
            'token' => new Assert\NotBlank(),
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\GreaterThan(0),
            ],
            'checkname' => new Assert\NotBlank(),
            'checkaba' => new Assert\NotBlank(),
            'checkaccount' => new Assert\NotBlank(),
        ]);

        return $this->validate($data, $constraint);
    }

    private function validate(?array $data, Assert\Collection $constraint): array
    {
        $violations = $this->validator->validate($data, $constraint);
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $errors;
    }
}
