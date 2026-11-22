<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeUsageReconciliationService;
use App\Services\Pixelforge\PixelforgeUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

function createReconciliationGeneration(
    User $user,
    string $status,
    string $completedAt,
    array $usage,
    string $providerCost
): Generation {
    return Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_'.uniqid(),
        'idempotency_key' => 'key_'.uniqid(),
        'provider_idempotency_key' => 'provider_'.uniqid(),
        'prompt' => 'Test generation',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => $status,
        'pricing_rates' => [
            'input_text' => '5.00',
            'input_image' => '10.00',
            'output' => '40.00',
            'reasoning' => '12.50',
        ],
        'usage' => $usage,
        'provider_cost' => $providerCost,
        'customer_price' => '0.000000000',
        'completed_at' => $completedAt,
    ]);
}

function fakeProviderUsage(array $dailyRecords): array
{
    return [
        'object' => 'usage',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-03',
        'data' => $dailyRecords,
    ];
}

function expectUsageMetricsToMatch(array $result): void
{
    expect($result['matched'])->toBeTrue();
}

it('reports matching provider and local usage', function () {
    createReconciliationGeneration(
        $this->user,
        'succeeded',
        '2026-11-01 10:00:00',
        [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
        ],
        '0.010000000'
    );

    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-01',
            'generations' => 1,
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'cost_usd' => '0.010000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->with('2026-11-01', '2026-11-03')
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expectUsageMetricsToMatch($result);
    expect($result['days']['2026-11-01']['differences']['provider_cost'])
        ->toBe('0.000000000');
});

it('reports token and cost discrepancies independently', function () {
    createReconciliationGeneration(
        $this->user,
        'succeeded',
        '2026-11-01 10:00:00',
        [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
        ],
        '0.010000000'
    );

    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-01',
            'generations' => 1,
            'input_text_tokens' => 50,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'cost_usd' => '0.020000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['matched'])->toBeFalse()
        ->and($result['days']['2026-11-01']['differences']['input_text_tokens'])
        ->toBe(9)
        ->and($result['days']['2026-11-01']['differences']['provider_cost'])
        ->toBe('0.010000000');
});

it('excludes failed generations from local usage', function () {
    createReconciliationGeneration(
        $this->user,
        'failed',
        '2026-11-01 10:00:00',
        [
            'input_text_tokens' => 100,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ],
        '0.000000000'
    );

    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-01',
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => '0.000000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['matched'])->toBeTrue();
});

it('detects provider usage when no matching local generation exists', function () {
    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-02',
            'generations' => 1,
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'cost_usd' => '0.010000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['matched'])->toBeFalse()
        ->and($result['days']['2026-11-02']['local']['generations'])
        ->toBe(0)
        ->and($result['days']['2026-11-02']['differences']['generations'])
        ->toBe(1);
});

it('preserves provider zero-usage days', function () {
    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-02',
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => '0.000000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['days'])
        ->toHaveKey('2026-11-02')
        ->and($result['days']['2026-11-02']['provider']['generations'])
        ->toBe(0);
});

it('does not modify local billing records during reconciliation', function () {
    $generation = createReconciliationGeneration(
        $this->user,
        'succeeded',
        '2026-11-01 10:00:00',
        [
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
        ],
        '0.010000000'
    );

    $originalCustomerPrice = $generation->customer_price;
    $originalProviderCost = $generation->provider_cost;

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn(fakeProviderUsage([]));

    app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    $generation->refresh();

    expect($generation->customer_price)->toBe($originalCustomerPrice)
        ->and($generation->provider_cost)->toBe($originalProviderCost);
});

it('detects local usage missing from the provider report', function () {
    createReconciliationGeneration(
        $this->user,
        'succeeded',
        '2026-11-02 10:00:00',
        [
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
        ],
        '0.010000000'
    );

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn(fakeProviderUsage([]));

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['matched'])->toBeFalse()
        ->and($result['days']['2026-11-02']['provider']['generations'])
        ->toBe(0)
        ->and($result['days']['2026-11-02']['local']['generations'])
        ->toBe(1)
        ->and($result['days']['2026-11-02']['differences']['generations'])
        ->toBe(-1);
});

it('groups local generations by their UTC completion date', function () {
    createReconciliationGeneration(
        $this->user,
        'succeeded',
        '2026-11-02 00:30:00',
        [
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
        ],
        '0.010000000'
    );

    $providerReport = fakeProviderUsage([
        [
            'date' => '2026-11-02',
            'generations' => 1,
            'input_text_tokens' => 20,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'cost_usd' => '0.010000000',
        ],
    ]);

    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn($providerReport);

    $result = app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');

    expect($result['matched'])->toBeTrue()
        ->and($result['days']['2026-11-02']['local']['generations'])
        ->toBe(1)
        ->and($result['days']['2026-11-01']['local']['generations'])
        ->toBe(0);
});

it('rejects an invalid provider usage date', function () {
    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn(fakeProviderUsage([
            [
                'date' => '2026-02-30',
                'generations' => 0,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => '0.000000000',
            ],
        ]));

    app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');
})->throws(InvalidArgumentException::class);

it('rejects an invalid provider cost', function () {
    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn(fakeProviderUsage([
            [
                'date' => '2026-11-01',
                'generations' => 0,
                'input_text_tokens' => 0,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => 'not-a-number',
            ],
        ]));

    app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');
})->throws(InvalidArgumentException::class);

it('rejects negative provider token counts', function () {
    $this->mock(PixelforgeUsageService::class)
        ->shouldReceive('getUsage')
        ->once()
        ->andReturn(fakeProviderUsage([
            [
                'date' => '2026-11-01',
                'generations' => 0,
                'input_text_tokens' => -10,
                'input_image_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'cost_usd' => '0.000000000',
            ],
        ]));

    app(PixelforgeUsageReconciliationService::class)
        ->reconcile('2026-11-01', '2026-11-03');
})->throws(InvalidArgumentException::class);
