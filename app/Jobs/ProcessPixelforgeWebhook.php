<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [5, 10, 30, 60, 120, 300];

    public function __construct(
        public readonly string $eventId
    ) {}

    public function handle(): void
    {
        $event = WebhookEvent::where('event_id', $this->eventId)
            ->first();

        if (! $event) {
            throw new RuntimeException(
                "Webhook event {$this->eventId} was not found."
            );
        }

        if ($event->processed_at !== null) {
            return;
        }

        /*
         * Generation state processing will be implemented in the next step.
         * For now, this job verifies that the event can be loaded.
         */
    }
}
