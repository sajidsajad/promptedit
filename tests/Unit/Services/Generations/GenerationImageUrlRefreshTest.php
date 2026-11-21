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

function createSucceededGenerationForImageRefresh(
    array $overrides = []
): Generation {
    $user = User::factory()->create();

    return Generation::create(array_merge([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_image_refresh_' . uniqid(),
        'idempotency_key' => 'local_' . uniqid(),
        'provider_idempotency_key' => 'provider_' . uniqid(),
        'request_hash' => hash('sha256', uniqid()),
        'prompt' => 'A cinematic mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'reference_images' => [],
        'metadata' => [],
        'pricing_rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'usage' => [
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'total_tokens' => 1320,
        ],
        'provider_cost' => '0.010000000',
        'customer_price' => '0.012500000',
        'image_url' => 'https://images.example.com/old-image.png',
        'image_url_expires_at' => now()->subMinute(),
        'started_at' => now()->subMinutes(2),
        'completed_at' => now()->subMinute(),
    ], $overrides));
}

function refreshedPixelforgeImageResponse(
    string $generationId,
    array $overrides = []
): array {
    return array_merge([
        'id' => $generationId,
        'object' => 'generation',
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/refreshed.png?signature=test',
        'image_url_expires_at' => now()->addHours(24)->toISOString(),
    ], $overrides);
}

it('refreshes an expired image URL from Pixelforge', function () {
    $generation = createSucceededGenerationForImageRefresh();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/*' => Http::response(
            refreshedPixelforgeImageResponse($generation->pixelforge_id),
            200
        ),
    ]);

    $result = app(GenerationProviderService::class)
        ->refreshImageUrl($generation);

    $result->refresh();

    expect($result->image_url)
        ->toBe('https://pixelforge.promptedit.dev/images/refreshed.png?signature=test')
        ->and($result->image_url_expires_at->isFuture())->toBeTrue();

    Http::assertSent(fn ($request) =>
        $request->method() === 'GET'
        && $request->url() ===
            "https://pixelforge.promptedit.dev/v1/generations/{$generation->pixelforge_id}"
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
    );
});

it('does not call Pixelforge when the image URL is still valid', function () {
    $generation = createSucceededGenerationForImageRefresh([
        'image_url_expires_at' => now()->addHours(2),
    ]);

    Http::fake();

    $result = app(GenerationProviderService::class)
        ->refreshImageUrl($generation);

    expect($result->image_url)->toBe($generation->image_url);

    Http::assertNothingSent();
});

it('does not refresh images for non-succeeded generations', function () {
    $generation = createSucceededGenerationForImageRefresh([
        'status' => 'processing',
        'image_url' => null,
        'image_url_expires_at' => null,
    ]);

    Http::fake();

    $result = app(GenerationProviderService::class)
        ->refreshImageUrl($generation);

    expect($result->status)->toBe('processing')
        ->and($result->image_url)->toBeNull();

    Http::assertNothingSent();
});

it('rejects a provider response for a different generation', function () {
    $generation = createSucceededGenerationForImageRefresh();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/*' => Http::response(
            refreshedPixelforgeImageResponse('gen_wrong_generation'),
            200
        ),
    ]);

    expect(fn () => app(GenerationProviderService::class)
        ->refreshImageUrl($generation)
    )->toThrow(UnexpectedValueException::class);

    $generation->refresh();

    expect($generation->image_url)
        ->toBe('https://images.example.com/old-image.png');
});

it('preserves the existing image when Pixelforge returns no refreshed URL', function () {
    $generation = createSucceededGenerationForImageRefresh();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/*' => Http::response(
            refreshedPixelforgeImageResponse(
                $generation->pixelforge_id,
                ['image_url' => null]
            ),
            200
        ),
    ]);

    expect(fn () => app(GenerationProviderService::class)
        ->refreshImageUrl($generation)
    )->toThrow(UnexpectedValueException::class);

    $generation->refresh();

    expect($generation->image_url)
        ->toBe('https://images.example.com/old-image.png');
});

it('preserves billing and lifecycle data when refreshing an image URL', function () {
    $generation = createSucceededGenerationForImageRefresh();

    $originalUsage = $generation->usage;
    $originalProviderCost = $generation->provider_cost;
    $originalCustomerPrice = $generation->customer_price;
    $originalCompletedAt = $generation->completed_at;

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/*' => Http::response(
            refreshedPixelforgeImageResponse($generation->pixelforge_id),
            200
        ),
    ]);

    $result = app(GenerationProviderService::class)
        ->refreshImageUrl($generation);

    $result->refresh();

    expect($result->status)->toBe('succeeded')
        ->and($result->usage)->toBe($originalUsage)
        ->and($result->provider_cost)->toBe($originalProviderCost)
        ->and($result->customer_price)->toBe($originalCustomerPrice)
        ->and($result->completed_at->equalTo($originalCompletedAt))->toBeTrue();
});

it('preserves local data when the provider request fails', function () {
    $generation = createSucceededGenerationForImageRefresh();

    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/*' => Http::response([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Provider unavailable',
            ],
        ], 503),
    ]);

    expect(fn () => app(GenerationProviderService::class)
        ->refreshImageUrl($generation)
    )->toThrow(RequestException::class);

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->image_url)
        ->toBe('https://images.example.com/old-image.png')
        ->and($generation->provider_cost)->toBe('0.010000000')
        ->and($generation->customer_price)->toBe('0.012500000');
});

