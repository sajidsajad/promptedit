<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(GenerationStateService $stateService): void
    {
        DB::transaction(function () use ($stateService): void {
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

            // A completed event must never be applied twice.
            if ($event->processed_at !== null) {
                return;
            }

            // The webhook can arrive before the POST response is saved.
            // Throwing allows Laravel's queue to retry this job.
            $generation = $event->generation_id
                ? Generation::query()
                    ->whereKey($event->generation_id)
                    ->lockForUpdate()
                    ->first()
                : Generation::query()
                    ->where('pixelforge_id', $event->provider_generation_id)
                    ->lockForUpdate()
                    ->first();

            if (! $generation) {
                throw new RuntimeException(
                    "Local generation for provider ID {$event->provider_generation_id} was not found."
                );
            }

            // Associate the event with the local generation.
            // This also supports webhooks received before the POST response.
            $event->generation_id = $generation->id;
            $providerGeneration = $event->payload['data']['object'] ?? null;

            if (! is_array($providerGeneration)) {
                throw new UnexpectedValueException(
                    'Webhook does not contain a valid generation object.'
                );
            }

            $providerId = $providerGeneration['id'] ?? null;

            if (
                ! is_string($providerId)
                || $providerId !== $event->provider_generation_id
            ) {
                throw new UnexpectedValueException(
                    'Webhook generation ID does not match the recorded provider generation ID.'
                );
            }

            $expectedStatus = match ($event->event_type) {
                'generation.processing' => 'processing',
                'generation.succeeded' => 'succeeded',
                'generation.failed' => 'failed',
                default => null,
            };

            if ($expectedStatus === null) {
                throw new UnexpectedValueException(
                    'Unsupported Pixelforge webhook event type.'
                );
            }

            // Shared service handles state transitions and exact billing.
            $stateService->apply(
                $event->generation_id,
                $providerGeneration,
                $expectedStatus
            );

            // Mark the event processed only after the state update succeeds.
            $event->processed_at = now();
            $event->processing_error = null;
            $event->save();
        });
    }

    /**
     * Record the final error after Laravel exhausts the retry attempts.
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
