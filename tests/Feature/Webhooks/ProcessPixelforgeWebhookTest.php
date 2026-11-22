<?php

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Services\Wallet\WalletReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Give a processing generation a wallet reservation.
 *
 * This simulates the reservation created before a generation
 * is submitted to Pixelforge.
 */
function reserveWebhookTestGeneration(Generation $generation): void
{
    $wallet = Wallet::firstOrCreate(
        ['user_id' => $generation->user_id],
        [
            'balance' => '0.000000000',
            'reserved_balance' => '0.000000000',
        ]
    );

    $wallet->update([
        'balance' => '10.000000000',
    ]);

    app(WalletReservationService::class)->reserve(
        $generation,
        '1.000000000'
    );
}

it('processes a successful generation webhook and calculates exact billing', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_001',
        'idempotency_key' => 'local-key-001',
        'provider_idempotency_key' => 'provider-key-001',
        'request_hash' => hash('sha256', 'test'),
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'processing',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);

    reserveWebhookTestGeneration($generation);

    $payload = [
        'id' => 'evt_test_success_001',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-20T10:00:00Z',
        'data' => [
            'object' => [
                'id' => 'gen_test_001',
                'object' => 'generation',
                'status' => 'succeeded',
                'image_url' => 'https://example.com/generated.png',
                'image_url_expires_at' => '2026-11-21T10:00:00Z',
                'usage' => [
                    'input_text_tokens' => 13,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 732,
                    'total_tokens' => 1845,
                ],
                'error' => null,
                'created_at' => '2026-11-20T09:59:00Z',
                'started_at' => '2026-11-20T09:59:10Z',
                'completed_at' => '2026-11-20T10:00:00Z',
            ],
        ],
    ];

    WebhookEvent::create([
        'event_id' => 'evt_test_success_001',
        'provider_generation_id' => 'gen_test_001',
        'event_type' => 'generation.succeeded',
        'payload' => $payload,
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_success_001');

    app()->call([$job, 'handle']);

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->usage['total_tokens'])->toBe(1845)
        ->and($generation->image_url)->toBe('https://example.com/generated.png')
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750');

    $event = WebhookEvent::where(
        'event_id',
        'evt_test_success_001'
    )->firstOrFail();

    expect($event->processed_at)->not->toBeNull();
});

it('processes a failed generation without charging the user', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_failed_001',
        'idempotency_key' => 'local-key-failed-001',
        'provider_idempotency_key' => 'provider-key-failed-001',
        'request_hash' => hash('sha256', 'failed-test'),
        'prompt' => '[fail] A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'processing',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);

    reserveWebhookTestGeneration($generation);

    $payload = [
        'id' => 'evt_test_failed_001',
        'object' => 'event',
        'type' => 'generation.failed',
        'created_at' => '2026-11-20T10:00:00Z',
        'data' => [
            'object' => [
                'id' => 'gen_test_failed_001',
                'object' => 'generation',
                'status' => 'failed',
                'usage' => null,
                'image_url' => null,
                'error' => [
                    'code' => 'generation_failed',
                    'message' => 'The image generation could not be completed.',
                ],
                'created_at' => '2026-11-20T09:59:00Z',
                'started_at' => '2026-11-20T09:59:10Z',
                'completed_at' => '2026-11-20T10:00:00Z',
            ],
        ],
    ];

    WebhookEvent::create([
        'event_id' => 'evt_test_failed_001',
        'provider_generation_id' => 'gen_test_failed_001',
        'event_type' => 'generation.failed',
        'payload' => $payload,
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_failed_001');

    app()->call([$job, 'handle']);

    $generation->refresh();

    expect($generation->status)->toBe('failed')
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe(
            'The image generation could not be completed.'
        )
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->customer_price)->toBe('0.000000000')
        ->and($generation->usage)->toBeNull()
        ->and($generation->image_url)->toBeNull();

    $event = WebhookEvent::where(
        'event_id',
        'evt_test_failed_001'
    )->firstOrFail();

    expect($event->processed_at)->not->toBeNull();
});

it('does not regress a succeeded generation when a delayed processing webhook arrives', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_order_001',
        'idempotency_key' => 'local-key-order-001',
        'provider_idempotency_key' => 'provider-key-order-001',
        'request_hash' => hash('sha256', 'order-test'),
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
            'total_tokens' => 1845,
        ],
        'provider_cost' => '0.053215000',
        'customer_price' => '0.066518750',
        'image_url' => 'https://example.com/generated.png',
    ]);

    $payload = [
        'id' => 'evt_test_delayed_processing_001',
        'object' => 'event',
        'type' => 'generation.processing',
        'created_at' => '2026-11-20T09:59:30Z',
        'data' => [
            'object' => [
                'id' => 'gen_test_order_001',
                'object' => 'generation',
                'status' => 'processing',
                'created_at' => '2026-11-20T09:59:00Z',
                'started_at' => '2026-11-20T09:59:10Z',
            ],
        ],
    ];

    $event = WebhookEvent::create([
        'event_id' => 'evt_test_delayed_processing_001',
        'provider_generation_id' => 'gen_test_order_001',
        'event_type' => 'generation.processing',
        'payload' => $payload,
    ]);

    $job = new ProcessPixelforgeWebhook(
        'evt_test_delayed_processing_001'
    );

    app()->call([$job, 'handle']);

    $generation->refresh();
    $event->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750')
        ->and($generation->image_url)->toBe(
            'https://example.com/generated.png'
        )
        ->and($event->generation_id)->toBe($generation->id)
        ->and($event->processed_at)->not->toBeNull();
});

it('does not process an already processed webhook event twice', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_duplicate_001',
        'idempotency_key' => 'local-key-duplicate-001',
        'provider_idempotency_key' => 'provider-key-duplicate-001',
        'request_hash' => hash('sha256', 'duplicate-test'),
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => [
            'input_text_tokens' => 13,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 732,
            'total_tokens' => 1845,
        ],
        'provider_cost' => '0.053215000',
        'customer_price' => '0.066518750',
        'image_url' => 'https://example.com/original.png',
    ]);

    $processedAt = now()->subMinute()->startOfSecond();

    $event = WebhookEvent::create([
        'event_id' => 'evt_test_duplicate_001',
        'generation_id' => $generation->id,
        'provider_generation_id' => 'gen_test_duplicate_001',
        'event_type' => 'generation.succeeded',
        'payload' => [],
        'processed_at' => $processedAt,
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_duplicate_001');

    // Simulate the queue executing the same job again.
    app()->call([$job, 'handle']);

    $generation->refresh();
    $event->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750')
        ->and($generation->image_url)->toBe(
            'https://example.com/original.png'
        )
        ->and($event->processed_at->equalTo($processedAt))->toBeTrue();
});

it('can retry an early webhook after its local generation becomes available', function () {
    $payload = [
        'id' => 'evt_test_early_001',
        'object' => 'event',
        'type' => 'generation.processing',
        'created_at' => '2026-11-20T10:00:00Z',
        'data' => [
            'object' => [
                'id' => 'gen_test_early_001',
                'object' => 'generation',
                'status' => 'processing',
                'created_at' => '2026-11-20T09:59:00Z',
                'started_at' => '2026-11-20T09:59:10Z',
            ],
        ],
    ];

    $event = WebhookEvent::create([
        'event_id' => 'evt_test_early_001',
        'provider_generation_id' => 'gen_test_early_001',
        'event_type' => 'generation.processing',
        'payload' => $payload,
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_early_001');

    // First attempt: Pixelforge has sent the webhook,
    // but the local generation does not exist yet.
    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class);

    $event->refresh();

    expect($event->processed_at)->toBeNull();

    // Simulate the original API response being saved.
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_early_001',
        'idempotency_key' => 'local-key-early-001',
        'provider_idempotency_key' => 'provider-key-early-001',
        'request_hash' => hash('sha256', 'early-test'),
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);

    // Second attempt: the generation now exists.
    app()->call([$job, 'handle']);

    $generation->refresh();
    $event->refresh();

    expect($generation->status)->toBe('processing')
        ->and($generation->started_at)->not->toBeNull()
        ->and($event->generation_id)->toBe($generation->id)
        ->and($event->processed_at)->not->toBeNull();
});

it('rejects a webhook when its payload generation ID does not match the recorded provider ID', function () {
    $user = User::factory()->create();

    Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_test_expected_001',
        'idempotency_key' => 'local-key-mismatch-001',
        'provider_idempotency_key' => 'provider-key-mismatch-001',
        'request_hash' => hash('sha256', 'mismatch-test'),
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'processing',
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);

    $payload = [
        'id' => 'evt_test_mismatch_001',
        'object' => 'event',
        'type' => 'generation.processing',
        'created_at' => '2026-11-20T10:00:00Z',
        'data' => [
            'object' => [
                'id' => 'gen_test_wrong_001',
                'object' => 'generation',
                'status' => 'processing',
            ],
        ],
    ];

    WebhookEvent::create([
        'event_id' => 'evt_test_mismatch_001',
        'provider_generation_id' => 'gen_test_expected_001',
        'event_type' => 'generation.processing',
        'payload' => $payload,
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_mismatch_001');

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(UnexpectedValueException::class);

    $event = WebhookEvent::where(
        'event_id',
        'evt_test_mismatch_001'
    )->firstOrFail();

    expect($event->processed_at)->toBeNull()
        ->and($event->generation_id)->toBeNull();
});

it('persists the processing error after the webhook job exhausts its retries', function () {
    WebhookEvent::create([
        'event_id' => 'evt_test_failed_job_001',
        'provider_generation_id' => 'gen_test_missing_001',
        'event_type' => 'generation.processing',
        'payload' => [],
    ]);

    $job = new ProcessPixelforgeWebhook('evt_test_failed_job_001');

    $job->failed(
        new RuntimeException('Local generation was not found after retries.')
    );

    $event = WebhookEvent::where(
        'event_id',
        'evt_test_failed_job_001'
    )->firstOrFail();

    expect($event->processing_error)->toBe(
        'Local generation was not found after retries.'
    )->and($event->processed_at)->toBeNull();
});
