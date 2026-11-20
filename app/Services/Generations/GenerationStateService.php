<?php

namespace App\Services\Generations;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationCostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class GenerationStateService
{
    public function __construct(
        private readonly GenerationCostCalculator $costCalculator
    ) {}

    /**
     * Apply an authoritative Pixelforge generation snapshot.
     *
     * The database transaction and row lock ensure that concurrent
     * webhook and reconciliation updates cannot overwrite each other.
     */
    public function apply(
        int $generationId,
        array $snapshot,
        ?string $expectedStatus = null
    ): void {
        DB::transaction(function () use (
            $generationId,
            $snapshot,
            $expectedStatus
        ): void {
            $generation = Generation::query()
                ->lockForUpdate()
                ->findOrFail($generationId);

            $providerId = $snapshot['id'] ?? null;

            if (
                ! is_string($providerId)
                || $providerId !== $generation->pixelforge_id
            ) {
                throw new UnexpectedValueException(
                    'Provider generation ID does not match the local generation.'
                );
            }

            $providerStatus = $snapshot['status'] ?? null;

            if (! in_array(
                $providerStatus,
                ['queued', 'processing', 'succeeded', 'failed'],
                true
            )) {
                throw new UnexpectedValueException(
                    'Pixelforge returned an unsupported generation status.'
                );
            }

            if (
                $expectedStatus !== null
                && $providerStatus !== $expectedStatus
            ) {
                throw new UnexpectedValueException(
                    'Webhook event type does not match its generation status.'
                );
            }

            // Terminal states can never be overwritten.
            if (in_array($generation->status, ['succeeded', 'failed'], true)) {
                return;
            }

            // A stale provider snapshot must not move processing backwards.
            if (
                $generation->status === 'processing'
                && $providerStatus === 'queued'
            ) {
                return;
            }

            $generation->status = $providerStatus;

            if (! empty($snapshot['started_at'])) {
                $generation->started_at = Carbon::parse(
                    $snapshot['started_at']
                );
            }

            if (! empty($snapshot['completed_at'])) {
                $generation->completed_at = Carbon::parse(
                    $snapshot['completed_at']
                );
            }

            if ($providerStatus === 'succeeded') {
                $usage = $snapshot['usage'] ?? null;

                if (! is_array($usage)) {
                    throw new UnexpectedValueException(
                        'A successful generation must include token usage.'
                    );
                }

                if (
                    ! is_array($generation->pricing_rates)
                    || $generation->pricing_rates === []
                ) {
                    throw new UnexpectedValueException(
                        'The generation has no saved pricing snapshot.'
                    );
                }

                $costs = $this->costCalculator->calculate(
                    $usage,
                    $generation->pricing_rates
                );

                $generation->usage = $usage;
                $generation->provider_cost = $costs['provider_cost'];
                $generation->customer_price = $costs['customer_price'];
                $generation->image_url = $snapshot['image_url'] ?? null;

                $generation->image_url_expires_at = ! empty(
                    $snapshot['image_url_expires_at']
                )
                    ? Carbon::parse($snapshot['image_url_expires_at'])
                    : null;

                $generation->error_code = null;
                $generation->error_message = null;
            }

            if ($providerStatus === 'failed') {
                $error = $snapshot['error'] ?? null;

                $generation->error_code = is_array($error)
                    ? ($error['code'] ?? null)
                    : null;

                $generation->error_message = is_array($error)
                    ? ($error['message'] ?? null)
                    : null;

                // Failed generations must never incur a charge.
                $generation->provider_cost = '0.000000000';
                $generation->customer_price = '0.000000000';
                $generation->usage = null;
                $generation->image_url = null;
                $generation->image_url_expires_at = null;
            }

            $generation->save();
        });
    }
}
