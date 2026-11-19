<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a user to have multiple generations', function () {
    $user = User::factory()->create();

    $first = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'request-001',
        'prompt' => 'First image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    $second = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'request-002',
        'prompt' => 'Second image',
        'size' => '1024x1024',
        'quality' => 'high',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    expect($user->generations)->toHaveCount(2)
        ->and($first->user->is($user))->toBeTrue()
        ->and($second->user->is($user))->toBeTrue();
});

it('stores webhook events against a generation', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'request-003',
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    $event = WebhookEvent::create([
        'event_id' => 'evt_test_001',
        'generation_id' => $generation->id,
        'event_type' => 'generation.succeeded',
        'payload' => ['object' => 'event'],
    ]);

    expect($generation->webhookEvents)->toHaveCount(1)
        ->and($event->generation->is($generation))->toBeTrue();
});

it('casts JSON fields and preserves decimal precision', function () {
    $user = User::factory()->create();

    $generation = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'request-004',
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'pricing_rates' => ['output_tokens' => '40.00'],
        'usage' => ['output_tokens' => 1100],
        'provider_cost' => '0.053215000',
        'customer_price' => '0.066518750',
    ]);

    $generation->refresh();

    expect($generation->usage)
        ->toBe(['output_tokens' => 1100])
        ->and($generation->pricing_rates['output_tokens'])
        ->toBe('40.00')
        ->and($generation->provider_cost)
        ->toBe('0.053215000')
        ->and($generation->customer_price)
        ->toBe('0.066518750');
});

it('prevents duplicate idempotency keys for the same user', function () {
    $user = User::factory()->create();

    $attributes = [
        'user_id' => $user->id,
        'idempotency_key' => 'duplicate-request',
        'prompt' => 'A mountain',
        'size' => '1024x1024',
        'quality' => 'medium',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ];

    Generation::create($attributes);

    expect(fn () => Generation::create($attributes))
        ->toThrow(QueryException::class);
});

it('allows different users to use the same idempotency key', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $attributes = [
        'idempotency_key' => 'same-key',
        'prompt' => 'A mountain',
        'size' => '1024x1024',
        'quality' => 'medium',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ];

    $first = Generation::create([
        ...$attributes,
        'user_id' => $firstUser->id,
    ]);

    $second = Generation::create([
        ...$attributes,
        'user_id' => $secondUser->id,
    ]);

    expect($first->exists)->toBeTrue()
        ->and($second->exists)->toBeTrue();
});

it('accepts a webhook before its generation is associated', function () {
    $event = WebhookEvent::create([
        'event_id' => 'evt_early_001',
        'generation_id' => null,
        'event_type' => 'generation.processing',
        'payload' => [
            'object' => 'event',
            'type' => 'generation.processing',
        ],
    ]);

    expect($event->exists)->toBeTrue()
        ->and($event->generation_id)->toBeNull()
        ->and($event->generation)->toBeNull();
});

it('prevents duplicate webhook event IDs', function () {
    $attributes = [
        'event_id' => 'evt_duplicate_001',
        'event_type' => 'generation.succeeded',
        'payload' => ['object' => 'event'],
    ];

    WebhookEvent::create($attributes);

    expect(fn () => WebhookEvent::create($attributes))
        ->toThrow(QueryException::class);
});
