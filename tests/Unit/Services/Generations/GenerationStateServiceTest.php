<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createGenerationForStateServiceTest(
    array $overrides = []
): Generation {
    $user = User::factory()->create();

    return Generation::create(array_merge([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_state_test_001',
        'idempotency_key' => 'state-test-key',
        'provider_idempotency_key' => 'provider-state-test-key',
        'request_hash' => hash('sha256', 'state-test'),
        'prompt' => 'A mountain landscape at sunset',
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
}

it('applies a successful provider snapshot and calculates billing', function () {
    $generation = createGenerationForStateServiceTest();

    app(GenerationStateService::class)->apply(
        $generation->id,
        [
            'id' => 'gen_state_test_001',
            'status' => 'succeeded',
            'started_at' => '2026-11-20T10:00:00Z',
            'completed_at' => '2026-11-20T10:00:30Z',
            'usage' => [
                'input_text_tokens' => 13,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 732,
                'total_tokens' => 1845,
            ],
            'image_url' => 'https://example.com/generated-image.png',
        ],
        'succeeded'
    );

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->usage['total_tokens'])->toBe(1845)
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750')
        ->and($generation->image_url)->toBe(
            'https://example.com/generated-image.png'
        )
        ->and($generation->started_at)->not->toBeNull()
        ->and($generation->completed_at)->not->toBeNull();
});

it('marks failed generations without charging the customer', function () {
    $generation = createGenerationForStateServiceTest();

    app(GenerationStateService::class)->apply(
        $generation->id,
        [
            'id' => 'gen_state_test_001',
            'status' => 'failed',
            'error' => [
                'code' => 'generation_failed',
                'message' => 'Image generation failed.',
            ],
        ],
        'failed'
    );

    $generation->refresh();

    expect($generation->status)->toBe('failed')
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe('Image generation failed.')
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->customer_price)->toBe('0.000000000')
        ->and($generation->usage)->toBeNull();
});

it('does not regress a processing generation to queued', function () {
    $generation = createGenerationForStateServiceTest([
        'status' => 'processing',
    ]);

    app(GenerationStateService::class)->apply(
        $generation->id,
        [
            'id' => 'gen_state_test_001',
            'status' => 'queued',
        ]
    );

    expect($generation->fresh()->status)->toBe('processing');
});

it('does not overwrite a terminal successful generation', function () {
    $generation = createGenerationForStateServiceTest([
        'status' => 'succeeded',
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

    app(GenerationStateService::class)->apply(
        $generation->id,
        [
            'id' => 'gen_state_test_001',
            'status' => 'failed',
            'error' => [
                'code' => 'generation_failed',
                'message' => 'Late failure event.',
            ],
        ]
    );

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost)->toBe('0.053215000')
        ->and($generation->customer_price)->toBe('0.066518750')
        ->and($generation->image_url)->toBe(
            'https://example.com/original.png'
        );
});

it('rejects a provider snapshot for a different generation', function () {
    $generation = createGenerationForStateServiceTest();

    expect(
        fn () => app(GenerationStateService::class)->apply(
            $generation->id,
            [
                'id' => 'gen_someone_else',
                'status' => 'processing',
            ]
        )
    )->toThrow(UnexpectedValueException::class);
});

it('rejects a webhook status that does not match its event type', function () {
    $generation = createGenerationForStateServiceTest();

    expect(
        fn () => app(GenerationStateService::class)->apply(
            $generation->id,
            [
                'id' => 'gen_state_test_001',
                'status' => 'processing',
            ],
            'succeeded'
        )
    )->toThrow(UnexpectedValueException::class);
});
