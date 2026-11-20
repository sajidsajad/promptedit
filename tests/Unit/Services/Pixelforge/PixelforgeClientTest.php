<?php

uses(Tests\TestCase::class);

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'services.pixelforge.api_key' => 'test-api-key',
    ]);
});

it('retrieves pricing without requiring authentication', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], 200),
    ]);

    $pricing = app(PixelforgeClient::class)->getPricing();

    expect($pricing['currency'])->toBe('usd')
        ->and($pricing['unit'])->toBe('per_1m_tokens')
        ->and($pricing['rates']['input_text_tokens'])->toBe('5.00')
        ->and($pricing['rates']['input_image_tokens'])->toBe('10.00')
        ->and($pricing['rates']['output_tokens'])->toBe('40.00')
        ->and($pricing['rates']['reasoning_tokens'])->toBe('12.50');

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
            && ! $request->hasHeader('Authorization');
    });
});

it('throws an exception when pricing retrieval fails', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Provider unavailable',
            ],
        ], 503),
    ]);

    expect(
        fn () => app(PixelforgeClient::class)->getPricing()
    )->toThrow(\Illuminate\Http\Client\RequestException::class);
});

it('submits generation requests with bearer authentication and idempotency', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_test123',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $result = app(PixelforgeClient::class)->createGeneration(
        [
            'prompt' => 'A mountain landscape',
            'size' => '1024x1024',
            'quality' => 'medium',
        ],
        'provider-idempotency-test'
    );

    expect($result['id'])->toBe('gen_test123')
        ->and($result['status'])->toBe('queued');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request->hasHeader(
                'Idempotency-Key',
                'provider-idempotency-test'
            )
            && $request['prompt'] === 'A mountain landscape';
    });
});


it('retrieves generation status with bearer authentication', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_test123' => Http::response([
            'id' => 'gen_test123',
            'object' => 'generation',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 13,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 732,
                'total_tokens' => 1845,
            ],
        ], 200),
    ]);

    $result = app(PixelforgeClient::class)
        ->getGeneration('gen_test123');

    expect($result['id'])->toBe('gen_test123')
        ->and($result['status'])->toBe('succeeded')
        ->and($result['usage']['total_tokens'])->toBe(1845);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations/gen_test123'
            && $request->hasHeader('Authorization', 'Bearer test-api-key');
    });
});
