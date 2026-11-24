<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function estimatePricingResponse(): array
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
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

function fakePricing(): void
{
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(estimatePricingResponse()),
    ]);
}

beforeEach(function () {
    Cache::flush();
    Queue::fake();
});

it('requires authentication for estimates', function () {
    $this->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->assertUnauthorized();
});

it('estimates the customer price with the 25 percent markup', function () {
    fakePricing();

    $user = User::factory()->create();

    // 40 chars -> 10 text tokens; medium output 1100; mid reasoning 1100.
    // Provider: 0.00005 + 0.044 + 0.01375 = 0.0578; x1.25 = 0.07225.
    // Maximum uses 12 text tokens and 2000 reasoning tokens:
    // 0.00006 + 0.044 + 0.025 = 0.06906; x1.25 = 0.086325.
    $this->actingAs($user)
        ->getJson('/generations/estimate?prompt_length=40&size=1024x1024&quality=medium')
        ->assertOk()
        ->assertJsonPath('data.estimated_price', '0.072250000')
        ->assertJsonPath('data.maximum_price', '0.086325000');
});

it('adds reference image tokens to the estimate', function () {
    fakePricing();

    $user = User::factory()->create();

    $without = $this->actingAs($user)
        ->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->json('data.estimated_price');

    $with = $this->actingAs($user)
        ->getJson('/generations/estimate?size=1024x1024&quality=low&reference_count=2')
        ->json('data.estimated_price');

    expect(bccomp($with, $without, 9))->toBe(1);
});

it('validates the estimate parameters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/generations/estimate?size=10x10&quality=ultra')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['size', 'quality']);
});

it('reports a pricing outage as unavailable instead of erroring', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(['error' => 'down'], 503),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->assertStatus(503);
});

it('refuses a generation the user cannot afford and creates nothing', function () {
    fakePricing();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'broke-001')
        ->postJson('/generations', ['prompt' => 'A mountain landscape'])
        ->assertStatus(402)
        ->assertJsonPath('code', 'insufficient_credits');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts a generation when available credits cover the maximum price', function () {
    fakePricing();

    $user = User::factory()->create();
    $user->wallet->update(['balance' => '1.000000000']);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'funded-001')
        ->postJson('/generations', ['prompt' => 'A mountain landscape'])
        ->assertAccepted();

    expect(Generation::count())->toBe(1);
});

it('does not count reserved credits as available', function () {
    fakePricing();

    $user = User::factory()->create();
    $user->wallet->update([
        'balance' => '1.000000000',
        'reserved_balance' => '0.999000000',
    ]);

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'reserved-001')
        ->postJson('/generations', ['prompt' => 'A mountain landscape'])
        ->assertStatus(402);
});

it('still replays an existing request after the balance drops', function () {
    fakePricing();

    $user = User::factory()->create();
    $user->wallet->update(['balance' => '1.000000000']);

    $payload = ['prompt' => 'A mountain landscape'];

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'replay-001')
        ->postJson('/generations', $payload)
        ->assertAccepted();

    $user->wallet->update(['balance' => '0.000000000']);

    $this->withHeader('Idempotency-Key', 'replay-001')
        ->postJson('/generations', $payload)
        ->assertAccepted()
        ->assertJsonPath('data.replayed', true);

    expect(Generation::count())->toBe(1);
});

it('does not block generation when pricing cannot be fetched', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(['error' => 'down'], 503),
    ]);

    $user = User::factory()->create();

    // The submission job re-checks the wallet when it reserves credits.
    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'outage-001')
        ->postJson('/generations', ['prompt' => 'A mountain landscape'])
        ->assertAccepted();
});

it('stores the estimate on the generation when it is submitted to Pixelforge', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(estimatePricingResponse()),
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_estimate1',
            'object' => 'generation',
            'status' => 'queued',
        ], 202),
    ]);

    $user = User::factory()->create();
    $user->wallet->update(['balance' => '1.000000000']);

    $generation = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'estimate-store',
        'provider_idempotency_key' => 'provider-estimate-store',
        'prompt' => str_repeat('x', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'pricing_rates' => [],
    ]);

    app(GenerationProviderService::class)->submit($generation);

    expect($generation->refresh()->estimated_price)->toBe('0.072250000');
});
