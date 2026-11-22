<?php

namespace App\Services\Generations;

use App\Services\Pixelforge\GenerationCostCalculator;
use InvalidArgumentException;

class GenerationReservationCalculator
{
    public function __construct(
        private readonly GenerationCostCalculator $costCalculator
    ) {}

    /**
     * Calculate the maximum customer amount to reserve before submission.
     *
     * Uses maximum estimated usage rather than expected average usage.
     * Monetary calculations use exact nine-decimal BCMath arithmetic.
     */
    public function calculate(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount,
        array $rates,
        array $tokenPricing
    ): string {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException(
                'Reference image count cannot be negative.'
            );
        }

        $charactersPerToken = $tokenPricing['characters_per_text_token'] ?? null;
        $variance = $tokenPricing['text_token_variance'] ?? null;
        $imageTokens = $tokenPricing['per_reference_image'] ?? null;

        if (
            ! is_int($charactersPerToken) ||
            $charactersPerToken <= 0 ||
            ! is_int($imageTokens) ||
            $imageTokens < 0 ||
            ! is_numeric($variance)
        ) {
            throw new InvalidArgumentException(
                'Incomplete or invalid token pricing configuration.'
            );
        }

        $varianceBasisPoints = $this->toBasisPoints($variance);

        $outputTokens = $tokenPricing['output'][$quality][$size] ?? null;
        $reasoningTokens = $tokenPricing['reasoning'][$quality]['max'] ?? null;

        if (
            ! is_int($outputTokens) ||
            $outputTokens < 0 ||
            ! is_int($reasoningTokens) ||
            $reasoningTokens < 0
        ) {
            throw new InvalidArgumentException(
                'Missing or invalid output or reasoning token pricing.'
            );
        }

        // Estimate maximum text tokens, including the allowed variance.
        $characterCount = mb_strlen($prompt, 'UTF-8');

        $numerator = $characterCount * (10000 + $varianceBasisPoints);
        $denominator = $charactersPerToken * 10000;

        // Integer ceiling division avoids floating-point rounding.
        $maximumTextTokens = intdiv(
            $numerator + $denominator - 1,
            $denominator
        );

        $usage = [
            'input_text_tokens' => $maximumTextTokens,
            'input_image_tokens' => $referenceImageCount * $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ];

        $costs = $this->costCalculator->calculate($usage, $rates);

        return $costs['customer_price'];
    }

    /**
     * Convert decimal variance to basis points without float arithmetic.
     *
     * Example: 0.15 becomes 1500 basis points.
     */
    private function toBasisPoints(int|float|string $variance): int
    {
        $value = (string) $variance;

        if (! preg_match('/^(0|1)(?:\.(\d{1,4}))?$/', $value, $matches)) {
            throw new InvalidArgumentException(
                'Text token variance must be between 0 and 1.'
            );
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 4, '0');

        $basisPoints = ($whole * 10000) + (int) $fraction;

        if ($basisPoints > 10000) {
            throw new InvalidArgumentException(
                'Text token variance cannot exceed 1.'
            );
        }

        return $basisPoints;
    }
}
