<?php

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationProviderService;
use App\Services\Generations\GenerationReservationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'services.pixelforge.api_key' => 'test-api-key',
    ]);
});

/**
 * Create a queued generation with a funded wallet.
 *
 * User creation automatically provisions the wallet, so we update
 * that existing wallet instead of creating a duplicate.
 */
function createQueuedGeneration(array $overrides = []): Generation
{
    $user = User::factory()->create();

    $user->wallet()->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    return Generation::create(array_merge([
        'user_id' => $user->id,
        'idempotency_key' => 'local-idempotency-key',
        'provider_idempotency_key' => 'provider-idempotency-key',
        'request_hash' => hash('sha256', 'test-request'),
        'prompt' => 'A mountain landscape at sunset',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'reference_images' => [],
        'metadata' => [],
        'pricing_rates' => [],
        'pricing_tokens' => [],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ], $overrides));
}

/**
 * Representative Pixelforge pricing response.
 */
function pixelforgePricingResponse(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',

        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],

        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,

            'output' => [
                'low' => [
                    '1024x1024' => 280,
                    '1536x1024' => 420,
                    '1024x1536' => 420,
                ],
                'medium' => [
                    '1024x1024' => 1100,
                    '1536x1024' => 1650,
                    '1024x1536' => 1650,
                ],
                'high' => [
                    '1024x1024' => 4300,
                    '1536x1024' => 6450,
                    '1024x1536' => 6450,
                ],
            ],

            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

it('snapshots pricing and submits a generation to Pixelforge', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(
            pixelforgePricingResponse(),
            200
        ),
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_test123',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $generation = createQueuedGeneration([
        'reference_images' => ['https://example.com/reference.png'],
        'metadata' => ['source' => 'test'],
    ]);

    $result = app(GenerationProviderService::class)->submit($generation);

    $result->refresh();

    $expectedReservation = app(
        GenerationReservationCalculator::class
    )->calculate(
        $result->prompt,
        $result->size,
        $result->quality,
        count($result->reference_images ?? []),
        $result->pricing_rates,
        $result->pricing_tokens
    );

    $wallet = $result->user->wallet()->first();

    expect($wallet)->not->toBeNull()
        ->and($wallet->reserved_balance)->toBe($expectedReservation);

    expect($result->pixelforge_id)->toBe('gen_test123')
        ->and($result->status)->toBe('queued')
        ->and($result->pricing_rates)->toBe(
            pixelforgePricingResponse()['rates']
        )
        ->and($result->pricing_tokens)->toBe(
            pixelforgePricingResponse()['tokens']
        );

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request->hasHeader(
                'Idempotency-Key',
                'provider-idempotency-key'
            )
            && $request['prompt'] === 'A mountain landscape at sunset'
            && $request['size'] === '1024x1024'
            && $request['quality'] === 'medium'
            && $request['reference_images'] === [
                'https://example.com/reference.png',
            ]
            && $request['metadata'] === ['source' => 'test'];
    });
});

it('does not submit a generation when pricing retrieval fails', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Provider unavailable',
            ],
        ], 503),
    ]);

    $generation = createQueuedGeneration();

    expect(
        fn () => app(GenerationProviderService::class)->submit($generation)
    )->toThrow(RequestException::class);

    $generation->refresh();

    expect($generation->pixelforge_id)->toBeNull()
        ->and($generation->status)->toBe('queued')
        ->and($generation->pricing_rates)->toBe([])
        ->and($generation->pricing_tokens)->toBe([]);

    Http::assertNotSent(
        fn ($request) => $request->method() === 'POST'
    );
});

it('keeps the pricing snapshot when generation submission fails', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(
            pixelforgePricingResponse(),
            200
        ),
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'overloaded',
                'message' => 'Provider is overloaded',
            ],
        ], 503),
    ]);

    $generation = createQueuedGeneration();

    expect(
        fn () => app(GenerationProviderService::class)->submit($generation)
    )->toThrow(RequestException::class);

    $generation->refresh();

    expect($generation->pricing_rates)->toBe(
        pixelforgePricingResponse()['rates']
    )
        ->and($generation->pricing_tokens)->toBe(
            pixelforgePricingResponse()['tokens']
        )
        ->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->status)->toBe('queued');
});

it('reuses the existing pricing snapshot when retrying submission', function () {
    $originalRates = [
        'input_text_tokens' => '4.50',
        'input_image_tokens' => '9.50',
        'output_tokens' => '38.00',
        'reasoning_tokens' => '11.50',
    ];

    $originalTokens = pixelforgePricingResponse()['tokens'];

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_retry123',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $generation = createQueuedGeneration([
        'pricing_rates' => $originalRates,
        'pricing_tokens' => $originalTokens,
    ]);

    $result = app(GenerationProviderService::class)->submit($generation);

    $result->refresh();

    expect($result->pixelforge_id)->toBe('gen_retry123')
        ->and($result->pricing_rates)->toBe($originalRates)
        ->and($result->pricing_tokens)->toBe($originalTokens);

    Http::assertNotSent(
        fn ($request) => $request->url() ===
            'https://pixelforge.promptedit.dev/v1/pricing'
    );
});

it('redispatches an early webhook after associating the provider generation ID', function () {
    Queue::fake();

    $generation = createQueuedGeneration([
        'pricing_rates' => pixelforgePricingResponse()['rates'],
        'pricing_tokens' => pixelforgePricingResponse()['tokens'],
    ]);

    $event = WebhookEvent::create([
        'event_id' => 'evt_test_early_dispatch_001',
        'generation_id' => null,
        'provider_generation_id' => 'gen_early_dispatch_001',
        'event_type' => 'generation.processing',
        'payload' => [
            'id' => 'evt_test_early_dispatch_001',
            'object' => 'event',
            'type' => 'generation.processing',
            'data' => [
                'object' => [
                    'id' => 'gen_early_dispatch_001',
                    'object' => 'generation',
                    'status' => 'processing',
                ],
            ],
        ],
    ]);

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_early_dispatch_001',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $result = app(GenerationProviderService::class)->submit($generation);

    $event->refresh();
    $result->refresh();

    expect($result->pixelforge_id)->toBe('gen_early_dispatch_001')
        ->and($event->generation_id)->toBe($result->id)
        ->and($event->processed_at)->toBeNull();

    Queue::assertPushed(
        ProcessPixelforgeWebhook::class,
        fn (ProcessPixelforgeWebhook $job) => $job->eventId ===
            'evt_test_early_dispatch_001'
    );
});

it('rejects an unexpected succeeded status in a generation creation response', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_unexpected_success_001',
            'object' => 'generation',
            'status' => 'succeeded',
        ], 202),
    ]);

    $generation = createQueuedGeneration([
        'pricing_rates' => pixelforgePricingResponse()['rates'],
        'pricing_tokens' => pixelforgePricingResponse()['tokens'],
    ]);

    expect(
        fn () => app(GenerationProviderService::class)->submit($generation)
    )->toThrow(UnexpectedValueException::class);

    $generation->refresh();

    expect($generation->pixelforge_id)->toBeNull()
        ->and($generation->status)->toBe('queued');
});

it('does not regress an already processing generation when the provider responds queued', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_already_processing_001',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $generation = createQueuedGeneration([
        'status' => 'processing',
        'pricing_rates' => pixelforgePricingResponse()['rates'],
        'pricing_tokens' => pixelforgePricingResponse()['tokens'],
        'started_at' => now()->subMinute(),
    ]);

    $result = app(GenerationProviderService::class)->submit($generation);

    $result->refresh();

    expect($result->pixelforge_id)->toBe('gen_already_processing_001')
        ->and($result->status)->toBe('processing')
        ->and($result->started_at)->not->toBeNull();
});

it('does not submit a generation when token pricing is invalid', function () {
    $pricing = pixelforgePricingResponse();

    unset($pricing['tokens']['output']['high']['1536x1024']);

    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(
            $pricing,
            200
        ),
    ]);

    $generation = createQueuedGeneration();

    expect(
        fn () => app(GenerationProviderService::class)->submit($generation)
    )->toThrow(UnexpectedValueException::class);

    $generation->refresh();

    expect($generation->pricing_rates)->toBe([])
        ->and($generation->pricing_tokens)->toBe([])
        ->and($generation->pixelforge_id)->toBeNull();

    Http::assertNotSent(
        fn ($request) => $request->method() === 'POST'
    );
});

it('rejects an incomplete existing pricing snapshot without provider requests', function () {
    $generation = createQueuedGeneration([
        'pricing_rates' => pixelforgePricingResponse()['rates'],
        'pricing_tokens' => [],
    ]);

    Http::fake();

    expect(
        fn () => app(GenerationProviderService::class)->submit($generation)
    )->toThrow(UnexpectedValueException::class);

    Http::assertNothingSent();
});
