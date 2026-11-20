<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileGeneration;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-stale-generations')]
#[Description('Queue reconciliation for stale Pixelforge generations')]
class ReconcileStaleGenerations extends Command
{
    public function handle(): int
    {
        $generationIds = Generation::query()
            ->whereIn('status', ['queued', 'processing'])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('updated_at')
            ->limit(100)
            ->pluck('id');

        foreach ($generationIds as $generationId) {
            ReconcileGeneration::dispatch((int) $generationId);
        }

        $this->info(
            "Queued {$generationIds->count()} generation reconciliation job(s)."
        );

        return self::SUCCESS;
    }
}
