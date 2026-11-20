<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\GenerationCostCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [5, 10, 30, 60, 120, 300];

    public function __construct(
        public readonly string $eventId
    ) {}

    public function handle(
        GenerationCostCalculator $costCalculator
    ): void {
        DB::transaction(function () use ($costCalculator): void {
            // Lock the event so two workers cannot process it simultaneously.
            $event = WebhookEvent::query()
                ->where('event_id', $this->eventId)
                ->lockForUpdate()
                ->first();

            if (! $event) {
                throw new RuntimeException(
                    "Webhook event {$this->eventId} was not found."
                );
            }

            // A previously completed event must never be applied twice.
            if ($event->processed_at !== null) {
                return;
            }

            // The webhook may arrive before our POST response is saved.
            // Throwing leaves the event unprocessed so the queue can retry.
            $generation = Generation::query()
                ->where('pixelforge_id', $event->provider_generation_id)
                ->lockForUpdate()
                ->first();

            if (! $generation) {
                throw new RuntimeException(
                    "Local generation for provider ID {$event->provider_generation_id} was not found."
                );
            }

            $providerGeneration = $event->payload['data']['object'] ?? null;

            if (! is_array($providerGeneration)) {
                throw new UnexpectedValueException(
                    'Webhook does not contain a valid generation object.'
                );
            }

            // Never apply an event to a generation other than the one
            // identified when the webhook was originally received.
            $payloadGenerationId = $providerGeneration['id'] ?? null;

            if (
                ! is_string($payloadGenerationId)
                || $payloadGenerationId !== $event->provider_generation_id
            ) {
                throw new UnexpectedValueException(
                    'Webhook generation ID does not match the recorded provider generation ID.'
                );
            }

            $providerStatus = $providerGeneration['status'] ?? null;

            if (! in_array(
                $providerStatus,
                ['queued', 'processing', 'succeeded', 'failed'],
                true
            )) {
                throw new UnexpectedValueException(
                    'Webhook contains an unsupported generation status.'
                );
            }

            // Terminal states are final. An older processing event must
            // never move a completed generation backwards.
            if (in_array($generation->status, ['succeeded', 'failed'], true)) {
                // Associate the delayed event with its local generation.
                $event->generation_id = $generation->id;

                // Mark it processed without changing the terminal generation state.
                $event->processed_at = now();
                $event->processing_error = null;
                $event->save();

                return;
            }

            // Verify that the event type agrees with its generation snapshot.
            $expectedStatus = match ($event->event_type) {
                'generation.processing' => 'processing',
                'generation.succeeded' => 'succeeded',
                'generation.failed' => 'failed',
                default => null,
            };

            if (
                $expectedStatus === null
                || $providerStatus !== $expectedStatus
            ) {
                throw new UnexpectedValueException(
                    'Webhook event type does not match its generation status.'
                );
            }

            // Apply the provider's state and timestamps.
            $generation->status = $providerStatus;

            if (! empty($providerGeneration['started_at'])) {
                $generation->started_at = Carbon::parse(
                    $providerGeneration['started_at']
                );
            }

            if (! empty($providerGeneration['completed_at'])) {
                $generation->completed_at = Carbon::parse(
                    $providerGeneration['completed_at']
                );
            }

            if ($providerStatus === 'succeeded') {
                $usage = $providerGeneration['usage'] ?? null;

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

                // Calculate from the rates saved when the generation was
                // created, never from today's provider pricing.
                $costs = $costCalculator->calculate(
                    $usage,
                    $generation->pricing_rates
                );

                $generation->usage = $usage;
                $generation->provider_cost = $costs['provider_cost'];
                $generation->customer_price = $costs['customer_price'];

                $generation->image_url = $providerGeneration['image_url'] ?? null;

                $generation->image_url_expires_at = ! empty(
                    $providerGeneration['image_url_expires_at']
                )
                    ? Carbon::parse(
                        $providerGeneration['image_url_expires_at']
                    )
                    : null;

                $generation->error_code = null;
                $generation->error_message = null;
            }

            if ($providerStatus === 'failed') {
                $error = $providerGeneration['error'] ?? null;

                $generation->error_code = is_array($error)
                    ? ($error['code'] ?? null)
                    : null;

                $generation->error_message = is_array($error)
                    ? ($error['message'] ?? null)
                    : null;

                // Failed generations are never billed.
                $generation->provider_cost = '0.000000000';
                $generation->customer_price = '0.000000000';
                $generation->usage = null;
                $generation->image_url = null;
                $generation->image_url_expires_at = null;
            }

            $generation->save();

            // Mark the event processed only after all updates succeed.
            $event->generation_id = $generation->id;
            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();
        });
    }

    /**
     * Laravel calls this after the job has exhausted its retry attempts.
     * Keep the error on the webhook event so it can be investigated.
     */
    public function failed(Throwable $exception): void
    {
        WebhookEvent::query()
            ->where('event_id', $this->eventId)
            ->whereNull('processed_at')
            ->update([
                'processing_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    5000
                ),
                'updated_at' => now(),
            ]);
    }
}
