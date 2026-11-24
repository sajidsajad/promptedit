<?php

namespace App\Services\Generations;

use App\Services\Pixelforge\GenerationCostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingValidator;
use Illuminate\Support\Facades\Cache;

/**
 * Estimates what a generation will cost the customer.
 *
 * Text and image input tokens are predictable, but output and reasoning
 * tokens are only known once Pixelforge finishes. The estimate therefore
 * uses the middle of the published reasoning range, and the maximum is
 * the amount reserved from the wallet before submission.
 */
class GenerationEstimator
{
    private const PRICING_CACHE_KEY = 'pixelforge:pricing';

    private const PRICING_CACHE_SECONDS = 300;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PricingValidator $validator,
        private readonly GenerationCostCalculator $costCalculator,
        private readonly GenerationReservationCalculator $reservationCalculator
    ) {}

    /**
     * Estimate using the current (briefly cached) Pixelforge pricing.
     *
     * @return array{estimated_price: string, maximum_price: string}
     */
    public function estimate(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount
    ): array {
        $pricing = $this->currentPricing();

        return $this->fromSnapshot(
            $prompt,
            $size,
            $quality,
            $referenceImageCount,
            $pricing['rates'],
            $pricing['tokens']
        );
    }

    /**
     * @return array{estimated_price: string, maximum_price: string}
     */
    public function fromSnapshot(
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount,
        array $rates,
        array $tokens
    ): array {
        $charactersPerToken = $tokens['characters_per_text_token'];

        // Ceiling division keeps the estimate in integer arithmetic.
        $textTokens = intdiv(
            mb_strlen($prompt, 'UTF-8') + $charactersPerToken - 1,
            $charactersPerToken
        );

        $reasoning = $tokens['reasoning'][$quality];

        $usage = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $referenceImageCount * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
            'reasoning_tokens' => intdiv($reasoning['min'] + $reasoning['max'], 2),
        ];

        return [
            'estimated_price' => $this->costCalculator
                ->calculate($usage, $rates)['customer_price'],
            'maximum_price' => $this->reservationCalculator->calculate(
                $prompt,
                $size,
                $quality,
                $referenceImageCount,
                $rates,
                $tokens
            ),
        ];
    }

    /**
     * @return array{rates: array, tokens: array}
     */
    private function currentPricing(): array
    {
        return Cache::remember(
            self::PRICING_CACHE_KEY,
            self::PRICING_CACHE_SECONDS,
            function (): array {
                $pricing = $this->client->getPricing();

                return [
                    'rates' => $this->validator->rates($pricing['rates'] ?? null),
                    'tokens' => $this->validator->tokens($pricing['tokens'] ?? null),
                ];
            }
        );
    }
}
