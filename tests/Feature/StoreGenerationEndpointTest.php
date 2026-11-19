<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication to create a generation', function () {
    $response = $this->postJson('/generations', [
        'prompt' => 'A mountain landscape',
    ]);

    $response->assertUnauthorized();
});

it('requires email verification to create a generation', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->postJson('/generations', [
        'prompt' => 'A mountain landscape',
    ]);

    $response->assertForbidden();
});

it('requires an idempotency key', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/generations', [
        'prompt' => 'A mountain landscape',
    ]);

    $response->assertUnprocessable();

    expect(Generation::count())->toBe(0);
});

it('creates only one generation for repeated identical requests', function () {
    $user = User::factory()->create();

    $payload = [
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'metadata' => [
            'source' => 'web',
            'campaign' => 'winter',
        ],
    ];

    $firstResponse = $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'request-001')
        ->postJson('/generations', $payload);

    $secondResponse = $this->withHeader('Idempotency-Key', 'request-001')
        ->postJson('/generations', $payload);

    $firstResponse->assertAccepted()
        ->assertJsonPath('data.replayed', false);

    $secondResponse->assertAccepted()
        ->assertJsonPath('data.replayed', true);

    expect(Generation::where('user_id', $user->id)->count())->toBe(1)
        ->and($firstResponse->json('data.id'))
        ->toBe($secondResponse->json('data.id'));
});

it('treats metadata key ordering as the same request', function () {
    $user = User::factory()->create();

    $firstResponse = $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'metadata-order-key')
        ->postJson('/generations', [
            'prompt' => 'A mountain landscape',
            'metadata' => [
                'source' => 'web',
                'campaign' => 'winter',
            ],
        ]);

    $secondResponse = $this->withHeader('Idempotency-Key', 'metadata-order-key')
        ->postJson('/generations', [
            'prompt' => 'A mountain landscape',
            'metadata' => [
                'campaign' => 'winter',
                'source' => 'web',
            ],
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

    $firstResponse->assertAccepted();
    $secondResponse->assertAccepted()
        ->assertJsonPath('data.replayed', true);

    expect(Generation::where('user_id', $user->id)->count())->toBe(1)
        ->and($firstResponse->json('data.id'))
        ->toBe($secondResponse->json('data.id'));
});

it('rejects reuse of an idempotency key with a different payload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'request-002')
        ->postJson('/generations', [
            'prompt' => 'A mountain landscape',
        ]);

    $response = $this->withHeader('Idempotency-Key', 'request-002')
        ->postJson('/generations', [
            'prompt' => 'A futuristic city',
        ]);

    $response->assertStatus(409);

    expect(Generation::where('user_id', $user->id)->count())->toBe(1);
});

it('allows different users to use the same idempotency key independently', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $payload = [
        'prompt' => 'A mountain landscape',
    ];

    $firstResponse = $this->actingAs($firstUser)
        ->withHeader('Idempotency-Key', 'shared-key')
        ->postJson('/generations', $payload);

    $secondResponse = $this->actingAs($secondUser)
        ->withHeader('Idempotency-Key', 'shared-key')
        ->postJson('/generations', $payload);

    $firstResponse->assertAccepted();
    $secondResponse->assertAccepted();

    expect(Generation::where('user_id', $firstUser->id)->count())->toBe(1)
        ->and(Generation::where('user_id', $secondUser->id)->count())->toBe(1);
});
