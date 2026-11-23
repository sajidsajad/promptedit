<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileGeneration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $generationId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        PixelforgeClient $client,
        GenerationStateService $stateService
    ): void {
        $generation = Generation::query()->find($this->generationId);

        if (
            ! $generation
            || ! $generation->pixelforge_id
            || in_array($generation->status, ['succeeded', 'failed'], true)
        ) {
            return;
        }

        $snapshot = $client->getGeneration($generation->pixelforge_id);

        $stateService->apply($generation->id, $snapshot);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Pixelforge generation reconciliation failed.', [
            'generation_id' => $this->generationId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
