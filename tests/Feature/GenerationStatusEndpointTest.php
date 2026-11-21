<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeGenerationForStatusTest(
    User $user,
    string $status = 'queued'
): Generation {
    return Generation::create([
        'user_id' => $user->id,
        'pixelforge_id' => 'gen_' . uniqid(),
        'idempotency_key' => 'request_' . uniqid(),
        'provider_idempotency_key' => 'provider_' . uniqid(),
        'request_hash' => hash('sha256', uniqid()),
        'prompt' => 'A cinematic mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => $status,
        'pricing_rates' => [
            'input_text' => '5.00',
            'input_image' => '10.00',
            'output' => '40.00',
            'reasoning' => '12.50',
        ],
        'usage' => $status === 'succeeded'
            ? [
                'input_text_tokens' => 20,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
                'total_tokens' => 1320,
            ]
            : null,
        'provider_cost' => $status === 'succeeded'
            ? '0.010000000'
            : '0.000000000',
        'customer_price' => $status === 'succeeded'
            ? '0.012500000'
            : '0.000000000',
        'image_url' => $status === 'succeeded'
            ? 'https://images.example.com/generated.png'
            : null,
        'image_url_expires_at' => $status === 'succeeded'
            ? now()->addHours(24)
            : null,
        'error_code' => $status === 'failed'
            ? 'generation_failed'
            : null,
        'error_message' => $status === 'failed'
            ? 'The image could not be generated.'
            : null,
        'started_at' => in_array($status, [
            'processing',
            'succeeded',
            'failed',
        ], true) ? now()->subMinute() : null,
        'completed_at' => in_array($status, [
            'succeeded',
            'failed',
        ], true) ? now() : null,
    ]);
}

it('allows an authenticated owner to retrieve their generation', function () {
    $user = User::factory()->create();

    $generation = makeGenerationForStatusTest($user);

    $response = $this->actingAs($user)
        ->getJson("/generations/{$generation->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $generation->id)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.prompt', 'A cinematic mountain landscape')
        ->assertJsonPath('data.size', '1024x1024')
        ->assertJsonPath('data.quality', 'medium');
});

it('requires authentication to retrieve a generation', function () {
    $user = User::factory()->create();

    $generation = makeGenerationForStatusTest($user);

    $this->getJson("/generations/{$generation->id}")
        ->assertUnauthorized();
});

it('prevents a user from retrieving another users generation', function () {
    $owner = User::factory()->create();
    $anotherUser = User::factory()->create();

    $generation = makeGenerationForStatusTest($owner);

    $this->actingAs($anotherUser)
        ->getJson("/generations/{$generation->id}")
        ->assertNotFound();
});

it('returns not found for a generation that does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/generations/999999')
        ->assertNotFound();
});

it('does not expose internal provider credentials or idempotency data', function () {
    $user = User::factory()->create();

    $generation = makeGenerationForStatusTest($user);

    $response = $this->actingAs($user)
        ->getJson("/generations/{$generation->id}");

    $response->assertOk()
        ->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.pixelforge_id')
        ->assertJsonMissingPath('data.idempotency_key')
        ->assertJsonMissingPath('data.provider_idempotency_key')
        ->assertJsonMissingPath('data.request_hash')
        ->assertJsonMissingPath('data.pricing_rates');
});

it('returns the correct data for every generation lifecycle status', function (
    string $status
) {
    $user = User::factory()->create();

    $generation = makeGenerationForStatusTest($user, $status);

    $response = $this->actingAs($user)
        ->getJson("/generations/{$generation->id}");

    $response->assertOk()
        ->assertJsonPath('data.status', $status);

    if ($status === 'succeeded') {
        $response->assertJsonPath(
            'data.provider_cost',
            '0.010000000'
        )->assertJsonPath(
            'data.customer_price',
            '0.012500000'
        )->assertJsonPath(
            'data.usage.output_tokens',
            1100
        );
    }

    if ($status === 'failed') {
        $response->assertJsonPath(
            'data.error_code',
            'generation_failed'
        );
    }
})->with([
    'queued' => 'queued',
    'processing' => 'processing',
    'succeeded' => 'succeeded',
    'failed' => 'failed',
]);
