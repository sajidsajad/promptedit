<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Generations\GenerationProviderService;
use App\Services\Wallet\WalletReleaseService;
use App\Services\Wallet\WalletReservationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

function createGenerationForSubmissionTest(array $overrides = []): Generation
{
    $user = User::factory()->create();

    $user->wallet()->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    $generation = Generation::create(array_merge([
        'user_id' => $user->id,
        'idempotency_key' => 'submission-test-key',
        'provider_idempotency_key' => 'provider-submission-test-key',
        'request_hash' => hash('sha256', 'submission-test'),
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'reference_images' => [],
        'metadata' => [],
        'pricing_rates' => [],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ], $overrides));

    // Only queued, not-yet-submitted generations should hold a reservation.
    if ($generation->status === 'queued' && $generation->pixelforge_id === null) {
        app(WalletReservationService::class)->reserve(
            $generation,
            '1.000000000'
        );
    }

    return $generation;
}

function providerRequestException(
    int $status,
    string $code,
    string $message,
    array $headers = []
): RequestException {
    $response = new Response(
        new GuzzleHttp\Psr7\Response(
            $status,
            $headers,
            json_encode([
                'error' => [
                    'type' => 'test_error',
                    'code' => $code,
                    'message' => $message,
                ],
            ])
        )
    );

    return new RequestException($response);
}

it('rethrows temporary concurrency limit errors so Laravel can retry', function () {
    $generation = createGenerationForSubmissionTest();

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldReceive('submit')
        ->once()
        ->andThrow(providerRequestException(
            429,
            'concurrency_limit_exceeded',
            'Three generations are already queued.'
        ));

    expect(
        fn () => app(SubmitGeneration::class, [
            'generationId' => $generation->id,
        ])->handle($provider, app(WalletReleaseService::class))
    )->toThrow(RequestException::class);

    $generation->refresh();

    expect($generation->user->wallet()->first()->reserved_balance)
        ->toBe('1.000000000');

    expect($generation->status)->toBe('queued')
        ->and($generation->error_code)->toBeNull()
        ->and($generation->provider_cost)->toBe('0.000000000');
});

it('rethrows temporary provider overload errors so Laravel can retry', function () {
    $generation = createGenerationForSubmissionTest();

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldReceive('submit')
        ->once()
        ->andThrow(providerRequestException(
            503,
            'overloaded',
            'Provider is temporarily overloaded.'
        ));

    expect(
        fn () => app(SubmitGeneration::class, [
            'generationId' => $generation->id,
        ])->handle($provider, app(WalletReleaseService::class))
    )->toThrow(RequestException::class);

    $generation->refresh();

    expect($generation->user->wallet()->first()->reserved_balance)
        ->toBe('1.000000000');

    expect($generation->status)->toBe('queued')
        ->and($generation->error_code)->toBeNull();
});

it('marks a permanent provider authentication rejection as failed without billing', function () {
    $generation = createGenerationForSubmissionTest();

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldReceive('submit')
        ->once()
        ->andThrow(providerRequestException(
            401,
            'invalid_api_key',
            'The API key is invalid.'
        ));

    app(SubmitGeneration::class, [
        'generationId' => $generation->id,
    ])->handle($provider, app(WalletReleaseService::class));

    $generation->refresh();

    $wallet = $generation->user->wallet()->first();

    $releaseTransaction = WalletTransaction::query()
        ->where('reference', "generation:{$generation->id}:release")
        ->first();

    expect($wallet->balance)->toBe('10.000000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and($releaseTransaction)->not->toBeNull()
        ->and($releaseTransaction->amount)->toBe('1.000000000');

    expect($generation->status)->toBe('failed')
        ->and($generation->error_code)->toBe('invalid_api_key')
        ->and($generation->error_message)->toBe('The API key is invalid.')
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->customer_price)->toBe('0.000000000')
        ->and($generation->usage)->toBeNull()
        ->and($generation->completed_at)->not->toBeNull();
});

it('does not submit a generation that already has a provider ID', function () {
    $generation = createGenerationForSubmissionTest([
        'pixelforge_id' => 'gen_already_submitted',
    ]);

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldNotReceive('submit');

    app(SubmitGeneration::class, [
        'generationId' => $generation->id,
    ])->handle($provider, app(WalletReleaseService::class));

    expect($generation->fresh()->pixelforge_id)
        ->toBe('gen_already_submitted');
});

it('records retry exhaustion without prematurely failing the generation', function () {
    $generation = createGenerationForSubmissionTest();

    $job = new SubmitGeneration($generation->id);

    $job->failed(
        new RuntimeException('Connection timed out after all attempts.')
    );

    $generation->refresh();

    expect($generation->user->wallet()->first()->reserved_balance)
        ->toBe('1.000000000');

    expect($generation->status)->toBe('queued')
        ->and($generation->error_code)->toBe('submission_retry_exhausted')
        ->and($generation->error_message)
        ->toBe('Connection timed out after all attempts.')
        ->and($generation->provider_cost)->toBe('0.000000000')
        ->and($generation->customer_price)->toBe('0.000000000');
});

it('does not overwrite a terminal generation when retries are exhausted', function () {
    $generation = createGenerationForSubmissionTest([
        'status' => 'succeeded',
        'pixelforge_id' => 'gen_completed',
        'provider_cost' => '0.010000000',
        'customer_price' => '0.012500000',
    ]);

    $job = new SubmitGeneration($generation->id);

    $job->failed(new RuntimeException('Late queue failure.'));

    $generation->refresh();

    expect($generation->status)->toBe('succeeded')
        ->and($generation->provider_cost)->toBe('0.010000000')
        ->and($generation->customer_price)->toBe('0.012500000')
        ->and($generation->error_code)->toBeNull();
});

it('uses the provider Retry-After header for concurrency limit errors', function () {
    $generation = createGenerationForSubmissionTest();

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldReceive('submit')
        ->once()
        ->andThrow(providerRequestException(
            429,
            'concurrency_limit_exceeded',
            'Three generations are already queued.',
            ['Retry-After' => '7']
        ));

    $job = new SubmitGeneration($generation->id);

    expect(
        fn () => $job->handle($provider, app(WalletReleaseService::class))
    )->toThrow(RequestException::class);

    expect($job->backoff())->toBe(7);
});

it('uses the provider Retry-After header for overload errors', function () {
    $generation = createGenerationForSubmissionTest();

    $provider = Mockery::mock(GenerationProviderService::class);

    $provider->shouldReceive('submit')
        ->once()
        ->andThrow(providerRequestException(
            503,
            'overloaded',
            'Provider is temporarily overloaded.',
            ['Retry-After' => '2']
        ));

    $job = new SubmitGeneration($generation->id);

    expect(
        fn () => $job->handle($provider, app(WalletReleaseService::class))
    )->toThrow(RequestException::class);

    expect($job->backoff())->toBe(2);
});
