<?php

namespace App\Services\Pixelforge;

use UnexpectedValueException;

/**
 * Validates the structures returned by GET /pricing before they are
 * stored on a generation or used for estimates.
 */
class PricingValidator
{
    /**
     * Validate Pixelforge token estimation rules before saving the snapshot.
     */
    public function tokens(mixed $tokens): array
    {
        if (! is_array($tokens)) {
            throw new UnexpectedValueException(
                'Pixelforge returned invalid token pricing.'
            );
        }

        $charactersPerToken = $tokens['characters_per_text_token'] ?? null;
        $variance = $tokens['text_token_variance'] ?? null;
        $imageTokens = $tokens['per_reference_image'] ?? null;

        if (
            ! is_int($charactersPerToken)
            || $charactersPerToken <= 0
            || ! is_numeric($variance)
            || ! is_int($imageTokens)
            || $imageTokens < 0
        ) {
            throw new UnexpectedValueException(
                'Pixelforge returned invalid token estimation settings.'
            );
        }

        // Accept a decimal variance between 0 and 1, with at most 4 decimals.
        $varianceString = (string) $variance;

        if (
            ! preg_match('/^(0|1)(?:\.(\d{1,4}))?$/', $varianceString, $matches)
            || (
                (int) $matches[1] === 1
                && isset($matches[2])
                && (int) $matches[2] !== 0
            )
        ) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid text token variance.'
            );
        }

        $qualities = ['low', 'medium', 'high'];
        $sizes = ['1024x1024', '1536x1024', '1024x1536'];

        $output = $tokens['output'] ?? null;
        $reasoning = $tokens['reasoning'] ?? null;

        if (! is_array($output) || ! is_array($reasoning)) {
            throw new UnexpectedValueException(
                'Pixelforge returned incomplete token pricing.'
            );
        }

        foreach ($qualities as $quality) {
            if (
                ! isset($output[$quality])
                || ! is_array($output[$quality])
                || ! isset($reasoning[$quality])
                || ! is_array($reasoning[$quality])
            ) {
                throw new UnexpectedValueException(
                    "Pixelforge returned incomplete token pricing for {$quality} quality."
                );
            }

            foreach ($sizes as $size) {
                if (
                    ! isset($output[$quality][$size])
                    || ! is_int($output[$quality][$size])
                    || $output[$quality][$size] < 0
                ) {
                    throw new UnexpectedValueException(
                        "Pixelforge returned invalid output tokens for {$quality} quality and {$size}."
                    );
                }
            }

            $minimum = $reasoning[$quality]['min'] ?? null;
            $maximum = $reasoning[$quality]['max'] ?? null;

            if (
                ! is_int($minimum)
                || ! is_int($maximum)
                || $minimum < 0
                || $maximum < $minimum
            ) {
                throw new UnexpectedValueException(
                    "Pixelforge returned invalid reasoning tokens for {$quality} quality."
                );
            }
        }

        return [
            'characters_per_text_token' => $charactersPerToken,
            'text_token_variance' => $variance,
            'per_reference_image' => $imageTokens,
            'output' => $output,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Validate the four provider rates before saving the snapshot.
     */
    public function rates(mixed $rates): array
    {
        $requiredRates = [
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
        ];

        if (! is_array($rates)) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid pricing response.'
            );
        }

        foreach ($requiredRates as $rateName) {
            $rate = $rates[$rateName] ?? null;

            if (
                ! is_string($rate)
|| ! preg_match('/^\d+(?:\.\d{1,9})?$/', $rate)
            ) {
                throw new UnexpectedValueException(
                    "Pixelforge returned an invalid rate or unsupported decimal precision: {$rateName}."
                );
            }
        }

        return array_intersect_key(
            $rates,
            array_flip($requiredRates)
        );
    }
}
