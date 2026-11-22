<?php

use App\Services\Pixelforge\PixelforgeUsageService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.promptedit.dev/v1',
        'services.pixelforge.api_key' => 'test-api-key',
    ]);
});

it('retrieves usage for a valid inclusive date range', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'data' => [
                [
                    'date' => '2026-11-01',
                    'generations' => 3,
                    'cost_usd' => '0.169080000',
                ],
                [
                    'date' => '2026-11-02',
                    'generations' => 0,
                    'cost_usd' => '0.000000000',
                ],
            ],
            'total' => [
                'generations' => 3,
                'cost_usd' => '0.169080000',
            ],
        ], 200),
    ]);

    $report = app(PixelforgeUsageService::class)->getUsage(
        '2026-11-01',
        '2026-11-02'
    );

    expect($report['object'])->toBe('usage')
        ->and($report['data'])->toHaveCount(2)
        ->and($report['data'][1]['generations'])->toBe(0)
        ->and($report['total']['cost_usd'])->toBe('0.169080000');
});

it('accepts a date range of exactly 92 inclusive days', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'object' => 'usage',
            'data' => [],
            'total' => [
                'generations' => 0,
                'cost_usd' => '0.000000000',
            ],
        ], 200),
    ]);

    $report = app(PixelforgeUsageService::class)->getUsage(
        '2026-01-01',
        '2026-04-02'
    );

    expect($report['object'])->toBe('usage');
});

it('rejects an invalid start date', function () {
    app(PixelforgeUsageService::class)->getUsage(
        '2026-02-30',
        '2026-03-02'
    );
})->throws(InvalidArgumentException::class);

it('rejects an invalid end date', function () {
    app(PixelforgeUsageService::class)->getUsage(
        '2026-11-01',
        'not-a-date'
    );
})->throws(InvalidArgumentException::class);

it('rejects a start date after the end date', function () {
    app(PixelforgeUsageService::class)->getUsage(
        '2026-11-10',
        '2026-11-01'
    );
})->throws(InvalidArgumentException::class);

it('rejects a date range longer than 92 inclusive days', function () {
    app(PixelforgeUsageService::class)->getUsage(
        '2026-01-01',
        '2026-04-03'
    );
})->throws(InvalidArgumentException::class);
