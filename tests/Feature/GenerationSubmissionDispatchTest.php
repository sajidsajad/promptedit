<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('dispatches one submission job when a new generation is created', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'submission-test-001')
        ->postJson('/generations', [
            'prompt' => 'A mountain landscape',
        ]);

    $response->assertAccepted()
        ->assertJsonPath('data.replayed', false);

    $generation = Generation::where('user_id', $user->id)->firstOrFail();

    Queue::assertPushed(
        SubmitGeneration::class,
        fn (SubmitGeneration $job) => $job->generationId === $generation->id
    );

    Queue::assertPushed(SubmitGeneration::class, 1);
});

it('does not dispatch another submission job for an idempotent replay', function () {
    Queue::fake();

    $user = User::factory()->create();

    $payload = [
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
    ];

    $firstResponse = $this->actingAs($user)
        ->withHeader('Idempotency-Key', 'submission-test-002')
        ->postJson('/generations', $payload);

    $secondResponse = $this->withHeader('Idempotency-Key', 'submission-test-002')
        ->postJson('/generations', $payload);

    $firstResponse->assertAccepted()
        ->assertJsonPath('data.replayed', false);

    $secondResponse->assertAccepted()
        ->assertJsonPath('data.replayed', true);

    Queue::assertPushed(SubmitGeneration::class, 1);
});
