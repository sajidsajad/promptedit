<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'services.pixelforge.api_key' => 'test-api-key',
    ]);
});

function createQueuedGeneration(array $overrides = []): Generation
{
    $user = User::factory()->create();

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
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ], $overrides));
}

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

    expect($result->pixelforge_id)->toBe('gen_test123')
        ->and($result->status)->toBe('queued')
        ->and($result->pricing_rates)->toBe(
            pixelforgePricingResponse()['rates']
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
        ->and($generation->pricing_rates)->toBe([]);

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
    )->and($generation->pixelforge_id)->toBeNull()
        ->and($generation->status)->toBe('queued');
});

it('reuses the existing pricing snapshot when retrying submission', function () {
    $originalRates = [
        'input_text_tokens' => '4.50',
        'input_image_tokens' => '9.50',
        'output_tokens' => '38.00',
        'reasoning_tokens' => '11.50',
    ];

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_retry123',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $generation = createQueuedGeneration([
        'pricing_rates' => $originalRates,
    ]);

    $result = app(GenerationProviderService::class)->submit($generation);

    $result->refresh();

    expect($result->pixelforge_id)->toBe('gen_retry123')
        ->and($result->pricing_rates)->toBe($originalRates);

    Http::assertNotSent(
        fn ($request) => $request->url() ===
            'https://pixelforge.promptedit.dev/v1/pricing'
    );
});
