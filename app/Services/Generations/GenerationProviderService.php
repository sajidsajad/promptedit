<?php

namespace App\Services\Generations;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
        $generation->refresh();

        // Never submit a generation again if its provider ID is already saved.
        if ($generation->pixelforge_id !== null) {
            return $generation;
        }

        // Reuse the original pricing snapshot when retrying.
        if (empty($generation->pricing_rates)) {
            $pricing = $this->pixelforge->getPricing();

            $rates = $this->validatePricingRates(
                $pricing['rates'] ?? null
            );

            $generation->pricing_rates = $rates;
            $generation->save();
        }

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

        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = $generation->reference_images;
        }

        if (! empty($generation->metadata)) {
            $payload['metadata'] = $generation->metadata;
        }

        // The provider idempotency key protects retries if the HTTP
        // request succeeds but our application does not receive its response.
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

        // A newly created generation must be queued.
        // Later state transitions are handled by verified webhooks.
        if ($providerStatus !== 'queued') {
            throw new UnexpectedValueException(
                'Pixelforge returned an unexpected status for a newly created generation.'
            );
        }

        // Save the provider reference and recover any webhooks that arrived
        // before the provider ID was associated with the local generation.
        $pendingEventIds = DB::transaction(function () use (
            $generation,
            $providerId
        ): array {
            $localGeneration = Generation::query()
                ->lockForUpdate()
                ->findOrFail($generation->id);

            // A local generation must never be associated with two
            // different provider generations.
            if (
                $localGeneration->pixelforge_id !== null
                && $localGeneration->pixelforge_id !== $providerId
            ) {
                throw new UnexpectedValueException(
                    'The local generation is already associated with another provider generation.'
                );
            }

            // Only associate the provider ID.
            // Do not overwrite the local status: a webhook may already
            // have advanced it beyond queued.
            $localGeneration->pixelforge_id = $providerId;
            $localGeneration->save();

            // Find every event for this provider generation.
            // Row locks prevent concurrent association updates.
            $events = WebhookEvent::query()
                ->where('provider_generation_id', $providerId)
                ->lockForUpdate()
                ->get();

            $pendingIds = [];

            foreach ($events as $event) {
                if (
                    $event->generation_id !== null
                    && $event->generation_id !== $localGeneration->id
                ) {
                    throw new UnexpectedValueException(
                        'A webhook event is already associated with another local generation.'
                    );
                }

                if ($event->generation_id === null) {
                    $event->generation_id = $localGeneration->id;
                    $event->save();
                }

                // Re-dispatch only events that have not been processed.
                if ($event->processed_at === null) {
                    $pendingIds[] = $event->event_id;
                }
            }

            return $pendingIds;
        });

        // Dispatch after the transaction commits. This ensures the job
        // can see the saved provider ID and event association.
        foreach ($pendingEventIds as $eventId) {
            ProcessPixelforgeWebhook::dispatch($eventId);
        }

        return $generation->refresh();
    }

    /**
     * Refresh an expired or missing Pixelforge image URL.
     *
     * This method changes only:
     * - image_url
     * - image_url_expires_at
     *
     * It never changes generation status, usage, pricing rates,
     * provider cost, customer price, or completion timestamps.
     */
    public function refreshImageUrl(Generation $generation): Generation
    {
        $generation->refresh();

        // Only successful generations can have an image.
        if (
            $generation->status !== 'succeeded'
            || empty($generation->pixelforge_id)
        ) {
            return $generation;
        }

        // Do not make an API request if the current URL is still valid.
        if (
            ! empty($generation->image_url)
            && $generation->image_url_expires_at !== null
            && $generation->image_url_expires_at->isFuture()
        ) {
            return $generation;
        }

        // Fetch the latest provider snapshot.
        // The provider API returns the generation fields at the top level.
        $snapshot = $this->pixelforge->getGeneration(
            $generation->pixelforge_id
        );

        // Ensure the provider returned the generation we requested.
        if (($snapshot['id'] ?? null) !== $generation->pixelforge_id) {
            throw new UnexpectedValueException(
                'Pixelforge returned a different generation ID.'
            );
        }

        // The provider must still consider this generation successful.
        if (($snapshot['status'] ?? null) !== 'succeeded') {
            throw new UnexpectedValueException(
                'Pixelforge generation is not in succeeded state.'
            );
        }

        $imageUrl = $snapshot['image_url'] ?? null;
        $expiresAt = $snapshot['image_url_expires_at'] ?? null;

        // Validate the refreshed URL.
        if (
            ! is_string($imageUrl)
            || filter_var($imageUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid image URL.'
            );
        }

        $scheme = parse_url($imageUrl, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnexpectedValueException(
                'Pixelforge image URL must use HTTP or HTTPS.'
            );
        }

        // Validate the expiry timestamp.
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            throw new UnexpectedValueException(
                'Pixelforge did not return an image URL expiry timestamp.'
            );
        }

        try {
            $parsedExpiry = CarbonImmutable::parse($expiresAt);
        } catch (\Throwable $exception) {
            throw new UnexpectedValueException(
                'Pixelforge returned an invalid image URL expiry timestamp.',
                previous: $exception
            );
        }

        if (! $parsedExpiry->isFuture()) {
            throw new UnexpectedValueException(
                'Pixelforge returned an already expired image URL.'
            );
        }

        // Lock the local row before saving. The provider request happens
        // outside the transaction to avoid holding a database lock during
        // network activity.
        return DB::transaction(function () use (
            $generation,
            $imageUrl,
            $parsedExpiry
        ): Generation {
            $localGeneration = Generation::query()
                ->lockForUpdate()
                ->findOrFail($generation->id);

            // The generation may have changed while the provider request
            // was running. Do not overwrite a different association/state.
            if (
                $localGeneration->status !== 'succeeded'
                || $localGeneration->pixelforge_id !== $generation->pixelforge_id
            ) {
                return $localGeneration;
            }

            // Another request may already have refreshed the URL.
            if (
                ! empty($localGeneration->image_url)
                && $localGeneration->image_url_expires_at !== null
                && $localGeneration->image_url_expires_at->isFuture()
            ) {
                return $localGeneration;
            }

            // Update image access only. Billing and lifecycle data remain intact.
            $localGeneration->image_url = $imageUrl;
            $localGeneration->image_url_expires_at = $parsedExpiry;
            $localGeneration->save();

            return $localGeneration;
        });
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

        return array_intersect_key(
            $rates,
            array_flip($requiredRates)
        );
    }
}

