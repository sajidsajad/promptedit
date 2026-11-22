<?php

namespace App\Services\Pixelforge;

use InvalidArgumentException;

class GenerationCostCalculator
{
    private const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function calculate(array $usage, array $rates): array
    {
        $this->validateInputs($usage, $rates);

        $providerCost = '0.000000000';

        foreach (self::TOKEN_TYPES as $type) {
            $lineCost = bcdiv(
                bcmul((string) $usage[$type], $rates[$type], 9),
                '1000000',
                9
            );

            $providerCost = bcadd($providerCost, $lineCost, 9);
        }

        $customerPrice = bcmul($providerCost, '1.25', 9);

        return [
            'provider_cost' => $providerCost,
            'customer_price' => $customerPrice,
        ];
    }

    private function validateInputs(array $usage, array $rates): void
    {
        foreach (self::TOKEN_TYPES as $type) {
            if (! array_key_exists($type, $usage)) {
                throw new InvalidArgumentException(
                    "Missing token usage field: {$type}"
                );
            }

            if (! is_int($usage[$type]) || $usage[$type] < 0) {
                throw new InvalidArgumentException(
                    "Token usage must be a non-negative integer: {$type}"
                );
            }

            if (! array_key_exists($type, $rates)) {
                throw new InvalidArgumentException(
                    "Missing pricing rate: {$type}"
                );
            }

            if (
                ! is_string($rates[$type]) ||
! preg_match('/^\d+(?:\.\d{1,9})?$/', $rates[$type])
            ) {
                throw new InvalidArgumentException(
                    "Pricing rate must be a non-negative decimal string: {$type}"
                );
            }
        }
    }
}
