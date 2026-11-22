<?php

namespace App\Services\Generations;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Wallet\WalletReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class GenerationProviderService
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly GenerationReservationCalculator $reservationCalculator,
        private readonly WalletReservationService $walletReservationService
    ) {}

    /**
     * Submit a locally-created generation to Pixelforge.
     */
    public function submit(Generation $generation): Generation
    {
        $generation->refresh();

        // Terminal generations must never be submitted again.
        if (in_array($generation->status, ['succeeded', 'failed'], true)) {
            return $generation;
        }

        // Never submit a generation again if its provider ID is already saved.
        if ($generation->pixelforge_id !== null) {
            return $generation;
        }
        // A pricing snapshot must contain both rates and token estimation rules.
        $hasRates = ! empty($generation->pricing_rates);
        $hasTokens = ! empty($generation->pricing_tokens);

        // Reject incomplete snapshots instead of mixing different pricing versions.
        if ($hasRates !== $hasTokens) {
            throw new UnexpectedValueException(
                'The generation has an incomplete pricing snapshot.'
            );
        }

        // Fetch pricing only when no snapshot exists.
        if (! $hasRates && ! $hasTokens) {
            // Network activity happens outside the database transaction.
            $pricing = $this->pixelforge->getPricing();

            // Validate both structures before attempting persistence.
            $rates = $this->validatePricingRates(
                $pricing['rates'] ?? null
            );

            $tokens = $this->validatePricingTokens(
                $pricing['tokens'] ?? null
            );

            DB::transaction(function () use (
                $generation,
                $rates,
                $tokens
            ): void {
                // Lock the generation to serialize concurrent snapshot writes.
                $localGeneration = Generation::query()
                    ->lockForUpdate()
                    ->findOrFail($generation->id);

                $hasLocalRates = ! empty($localGeneration->pricing_rates);
                $hasLocalTokens = ! empty($localGeneration->pricing_tokens);

                // Never allow an incomplete snapshot.
                if ($hasLocalRates !== $hasLocalTokens) {
                    throw new UnexpectedValueException(
                        'The generation has an incomplete pricing snapshot.'
                    );
                }

                // Another worker may have saved pricing while we were
                // fetching it. Preserve that worker's complete snapshot.
                if (! $hasLocalRates && ! $hasLocalTokens) {
                    $localGeneration->pricing_rates = $rates;
                    $localGeneration->pricing_tokens = $tokens;
                    $localGeneration->save();
                }
            });

            // Use the snapshot that actually won the database race.
            $generation->refresh();
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

        // Calculate the maximum customer charge using the saved pricing snapshot.
        // This includes the 25% markup and protects against under-reserving.
        $referenceImageCount = count($generation->reference_images ?? []);

        $reservationAmount = $this->reservationCalculator->calculate(
            $generation->prompt,
            $generation->size,
            $generation->quality,
            $referenceImageCount,
            $generation->pricing_rates,
            $generation->pricing_tokens
        );

        // Reserve credits before contacting Pixelforge.
        // This operation is idempotent, so retries will not reserve twice.
        $this->walletReservationService->reserve(
            $generation,
            $reservationAmount
        );

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
     * Validate Pixelforge token estimation rules before saving the snapshot.
     */
    private function validatePricingTokens(mixed $tokens): array
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
