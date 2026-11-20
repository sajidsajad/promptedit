<?php

namespace App\Services\Generations;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use UnexpectedValueException;

class GenerationProviderService
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge
    ) {}

    /**
     * Submit a locally-created generation to Pixelforge.
     */
    public function submit(Generation $generation): Generation
    {
        // Refresh the record so we work with the latest database state.
        $generation->refresh();

        // A generation already submitted to Pixelforge must not be
        // submitted again unnecessarily.
        if ($generation->pixelforge_id !== null) {
            return $generation;
        }

        // Reuse the original pricing snapshot when retrying.
        // Fetch pricing only for a generation that has no snapshot yet.
        if (empty($generation->pricing_rates)) {
            $pricing = $this->pixelforge->getPricing();

            $rates = $this->validatePricingRates(
                $pricing['rates'] ?? null
            );

            // Persist pricing before making the generation request.
            // If submission fails, a retry will use these same rates.
            $generation->pricing_rates = $rates;
            $generation->save();
        }

        // The provider idempotency key must remain unchanged across retries.
        if (
            ! is_string($generation->provider_idempotency_key)
            || trim($generation->provider_idempotency_key) === ''
        ) {
            throw new UnexpectedValueException(
                'The provider idempotency key is missing.'
            );
        }

        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
        ];

        // Optional fields are sent only when they contain values.
        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = $generation->reference_images;
        }

        if (! empty($generation->metadata)) {
            $payload['metadata'] = $generation->metadata;
        }

        // If the provider accepts the request but our connection times out,
        // a retry with the same key returns the original generation.
        $response = $this->pixelforge->createGeneration(
            $payload,
            $generation->provider_idempotency_key
        );

        $providerId = $response['id'] ?? null;
        $providerStatus = $response['status'] ?? null;

        if (
            ! is_string($providerId)
            || ! str_starts_with($providerId, 'gen_')
        ) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid generation ID.'
            );
        }

        $allowedStatuses = [
            'queued',
            'processing',
            'succeeded',
            'failed',
        ];

        if (
            ! is_string($providerStatus)
            || ! in_array($providerStatus, $allowedStatuses, true)
        ) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid generation status.'
            );
        }

        // Save the provider reference and its current status.
        $generation->pixelforge_id = $providerId;
        $generation->status = $providerStatus;
        $generation->save();

        return $generation->refresh();
    }

    /**
     * Validate the four provider rates before saving the snapshot.
     */
    private function validatePricingRates(mixed $rates): array
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
                || ! preg_match('/^\d+(\.\d+)?$/', $rate)
            ) {
                throw new UnexpectedValueException(
                    "Pixelforge returned an invalid rate: {$rateName}."
                );
            }
        }

        // Store only the four rates used by our cost calculator.
        return array_intersect_key(
            $rates,
            array_flip($requiredRates)
        );
    }
}
