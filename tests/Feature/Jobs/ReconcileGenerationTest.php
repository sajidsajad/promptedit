<?php

use App\Jobs\ReconcileGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Wallet\WalletReservationService;
use Illuminate\Support\Facades\Http;

function createGenerationForReconciliationTest(
    array $overrides = []
): Generation {
    $user = User::factory()->create();

    // Give the test user enough credits to cover the reservation.
    $user->wallet()->firstOrFail()->update([
        'balance' => '10.000000000',
    ]);

    $generation = Generation::create(array_merge([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_reconcile_test_001',
        'idempotency_key' => 'reconcile-test-key',
        'provider_idempotency_key' => 'provider-reconcile-test-key',
        'request_hash' => hash('sha256', 'reconcile-test'),
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'processing',
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
        'started_at' => now()->subMinutes(3),
    ], $overrides));

    // Only active generations with a provider ID need a reservation.
    if (
        $generation->pixelforge_id !== null
        && in_array($generation->status, ['queued', 'processing'], true)
    ) {
        app(WalletReservationService::class)->reserve(
            $generation,
            '1.000000000'
        );
    }

    return $generation;
}

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'services.pixelforge.api_key' => 'test-api-key',
    ]);
});

it('reconciles a successful generation and calculates billing', function () {
    $generation = createGenerationForReconciliationTest();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_reconcile_test_001' => Http::response([
            'id' => 'gen_reconcile_test_001',
            'object' => 'generation',
            'status' => 'succeeded',
            'started_at' => '2026-11-20T10:00:00Z',
            'completed_at' => '2026-11-20T10:01:00Z',
            'usage' => [
                'input_text_tokens' => 13,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 732,
                'total_tokens' => 1845,
            ],
            'image_url' => 'https://example.com/reconciled-image.png',
        ], 200),
    ]);

    app(ReconcileGeneration::class, [
        'generationId' => $generation->id,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750')
        ->and($generation->usage['total_tokens'])->toBe(1845)
        ->and($generation->image_url)
        ->toBe('https://example.com/reconciled-image.png');

    Http::assertSentCount(1);
});

it('reconciles a failed generation without charging the customer', function () {
    $generation = createGenerationForReconciliationTest();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_reconcile_test_001' => Http::response([
            'id' => 'gen_reconcile_test_001',
            'object' => 'generation',
            'status' => 'failed',
            'error' => [
                'code' => 'generation_failed',
                'message' => 'The image could not be generated.',
            ],
        ], 200),
    ]);

    app(ReconcileGeneration::class, [
        'generationId' => $generation->id,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    $generation->refresh();

    expect($generation->status)->toBe('failed')
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->customer_price)->toBe('0.000000000');
});

it('does not query Pixelforge for a terminal generation', function () {
    $generation = createGenerationForReconciliationTest([
        'status' => 'succeeded',
        'provider_cost' => '0.053215000',
        'customer_price' => '0.066518750',
    ]);

    Http::fake();

    app(ReconcileGeneration::class, [
        'generationId' => $generation->id,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    Http::assertNothingSent();
});

it('does not query Pixelforge when the local generation does not exist', function () {
    Http::fake();

    app(ReconcileGeneration::class, [
        'generationId' => 999999,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    Http::assertNothingSent();
});

it('does not query Pixelforge when no provider ID is available', function () {
    $generation = createGenerationForReconciliationTest([
        'pixelforge_id' => null,
    ]);

    Http::fake();

    app(ReconcileGeneration::class, [
        'generationId' => $generation->id,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    Http::assertNothingSent();
});

it('does not regress a processing generation when Pixelforge returns queued', function () {
    $generation = createGenerationForReconciliationTest();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_reconcile_test_001' => Http::response([
            'id' => 'gen_reconcile_test_001',
            'object' => 'generation',
            'status' => 'queued',
        ], 200),
    ]);

    app(ReconcileGeneration::class, [
        'generationId' => $generation->id,
    ])->handle(
        app(PixelforgeClient::class),
        app(GenerationStateService::class)
    );

    expect($generation->fresh()->status)->toBe('processing');
});

it('keeps its unique lock longer than its retry window', function () {
    $generation = createGenerationForReconciliationTest();

    $job = new ReconcileGeneration($generation->id);

    expect($job->uniqueFor)->toBe(300)
        ->and($job->uniqueId())->toBe((string) $generation->id);
});
