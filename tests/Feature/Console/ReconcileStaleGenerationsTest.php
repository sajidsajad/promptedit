<?php

use App\Jobs\ReconcileGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

function createGenerationForReconciliationCommandTest(
    array $overrides = []
): Generation {
    $user = User::factory()->create();

    $updatedAt = $overrides['updated_at'] ?? now();

    unset($overrides['updated_at']);

    $generation = Generation::create(array_merge([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_command_test_'.uniqid(),
        'idempotency_key' => 'command-test-'.uniqid(),
        'provider_idempotency_key' => 'provider-command-test-'.uniqid(),
        'request_hash' => hash('sha256', uniqid()),
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'reference_images' => [],
        'metadata' => [],
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ], $overrides));

    $generation->forceFill([
        'updated_at' => $updatedAt,
    ])->saveQuietly();

    return $generation;
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches reconciliation for stale queued generations', function () {
    $generation = createGenerationForReconciliationCommandTest([
        'status' => 'queued',
        'updated_at' => now()->subMinutes(3),
    ]);

    Bus::fake();

    Artisan::call('app:reconcile-stale-generations');

    Bus::assertDispatched(
        ReconcileGeneration::class,
        fn (ReconcileGeneration $job) => $job->generationId === $generation->id
    );
});

it('dispatches reconciliation for stale processing generations', function () {
    $generation = createGenerationForReconciliationCommandTest([
        'status' => 'processing',
        'updated_at' => now()->subMinutes(3),
    ]);

    Bus::fake();

    Artisan::call('app:reconcile-stale-generations');

    Bus::assertDispatched(
        ReconcileGeneration::class,
        fn (ReconcileGeneration $job) => $job->generationId === $generation->id
    );
});

it('does not dispatch reconciliation for fresh generations', function () {
    createGenerationForReconciliationCommandTest([
        'status' => 'processing',
        'updated_at' => now()->subMinute(),
    ]);

    Bus::fake();

    Artisan::call('app:reconcile-stale-generations');

    Bus::assertNotDispatched(ReconcileGeneration::class);
});

it('ignores terminal generations and generations without a provider ID', function () {
    createGenerationForReconciliationCommandTest([
        'status' => 'succeeded',
        'updated_at' => now()->subMinutes(5),
    ]);

    createGenerationForReconciliationCommandTest([
        'status' => 'failed',
        'updated_at' => now()->subMinutes(5),
    ]);

    createGenerationForReconciliationCommandTest([
        'status' => 'queued',
        'pixelforge_id' => null,
        'updated_at' => now()->subMinutes(5),
    ]);

    Bus::fake();

    Artisan::call('app:reconcile-stale-generations');

    Bus::assertNotDispatched(ReconcileGeneration::class);
});
