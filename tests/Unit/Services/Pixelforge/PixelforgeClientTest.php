<?php

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

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
    )->toThrow(RequestException::class);
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

it('retrieves provider usage with bearer authentication and date parameters', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'data' => [
                [
                    'date' => '2026-11-01',
                    'generations' => 3,
                    'input_text_tokens' => 41,
                    'input_image_tokens' => 0,
                    'output_tokens' => 3300,
                    'reasoning_tokens' => 2950,
                    'cost_usd' => '0.169080000',
                ],
                [
                    'date' => '2026-11-02',
                    'generations' => 0,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.000000000',
                ],
            ],
            'total' => [
                'generations' => 3,
                'input_text_tokens' => 41,
                'input_image_tokens' => 0,
                'output_tokens' => 3300,
                'reasoning_tokens' => 2950,
                'cost_usd' => '0.169080000',
            ],
        ], 200),
    ]);

    $result = app(PixelforgeClient::class)->getUsage(
        '2026-11-01',
        '2026-11-02'
    );

    expect($result['object'])->toBe('usage')
        ->and($result['start_date'])->toBe('2026-11-01')
        ->and($result['end_date'])->toBe('2026-11-02')
        ->and($result['data'])->toHaveCount(2)
        ->and($result['data'][0]['cost_usd'])->toBe('0.169080000')
        ->and($result['data'][1]['generations'])->toBe(0)
        ->and($result['data'][1]['cost_usd'])->toBe('0.000000000')
        ->and($result['total']['cost_usd'])->toBe('0.169080000');

    Http::assertSent(function ($request) {
        parse_str(
            parse_url($request->url(), PHP_URL_QUERY) ?? '',
            $query
        );

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/v1/usage'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $query === [
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-02',
            ];
    });
});

it('throws an exception when provider usage retrieval fails', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'error' => [
                'code' => 'invalid_api_key',
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    expect(
        fn () => app(PixelforgeClient::class)->getUsage(
            '2026-11-01',
            '2026-11-02'
        )
    )->toThrow(RequestException::class);
});
