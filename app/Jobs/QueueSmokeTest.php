<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class QueueSmokeTest implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('PROMPTEDIT_MANAGED_QUEUE_SMOKE_TEST', [
            'processed_at' => now()->toIso8601String(),
            'connection' => config('queue.default'),
        ]);
    }
}
