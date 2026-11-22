<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-stale-generations')]
#[Description('Recover stale Pixelforge generation submissions and reconcile their status')]
class ReconcileStaleGenerations extends Command
{
    public function handle(): int
    {
        $staleBefore = now()->subMinutes(2);

        // Recover submissions whose queue retries were exhausted.
        // Keep the original provider idempotency key when retrying.
        $submissionIds = Generation::query()
            ->where('status', 'queued')
            ->whereNull('pixelforge_id')
            ->where('error_code', 'submission_retry_exhausted')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('updated_at')
            ->limit(100)
            ->pluck('id');

        foreach ($submissionIds as $generationId) {
            SubmitGeneration::dispatch((int) $generationId);
        }

        // Reconcile generations already accepted by Pixelforge.
        $reconciliationIds = Generation::query()
            ->whereIn('status', ['queued', 'processing'])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('updated_at')
            ->limit(100)
            ->pluck('id');

        foreach ($reconciliationIds as $generationId) {
            ReconcileGeneration::dispatch((int) $generationId);
        }

        $this->info(
            "Queued {$submissionIds->count()} submission recovery job(s)."
        );

        $this->info(
            "Queued {$reconciliationIds->count()} generation reconciliation job(s)."
        );

        return self::SUCCESS;
    }
}
