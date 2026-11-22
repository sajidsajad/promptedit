<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWalletReservationGeneration(User $user): Generation
{
    return Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'wallet-reservation-test-'.$user->id,
        'provider_idempotency_key' => 'wallet-provider-test-'.$user->id,
        'request_hash' => hash('sha256', 'wallet-reservation-test-'.$user->id),
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'queued',
        'reference_images' => [],
        'metadata' => [],
        'pricing_rates' => [],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);
}

it('reserves credits without reducing the total wallet balance', function () {
    $user = User::factory()->create();

    $wallet = $user->wallet()->firstOrFail();
    $wallet->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    $generation = createWalletReservationGeneration($user);

    app(WalletReservationService::class)->reserve(
        $generation,
        '2.500000000'
    );

    $wallet->refresh();

    expect($wallet->balance)->toBe('10.000000000')
        ->and($wallet->reserved_balance)->toBe('2.500000000')
        ->and(bcsub($wallet->balance, $wallet->reserved_balance, 9))
        ->toBe('7.500000000');

    $transaction = WalletTransaction::query()->firstOrFail();

    expect($transaction->type)->toBe('reserve')
        ->and($transaction->amount)->toBe('2.500000000')
        ->and($transaction->balance_after)->toBe('10.000000000')
        ->and($transaction->reserved_balance_after)->toBe('2.500000000')
        ->and($transaction->generation_id)->toBe($generation->id);
});

it('rejects a reservation when available credits are insufficient', function () {
    $user = User::factory()->create();

    $user->wallet()->update([
        'balance' => '5.000000000',
        'reserved_balance' => '3.000000000',
    ]);

    $generation = createWalletReservationGeneration($user);

    expect(
        fn () => app(WalletReservationService::class)->reserve(
            $generation,
            '2.500000000'
        )
    )->toThrow(RuntimeException::class);

    $wallet = $user->wallet()->firstOrFail();

    expect($wallet->balance)->toBe('5.000000000')
        ->and($wallet->reserved_balance)->toBe('3.000000000')
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('does not reserve credits twice for the same generation', function () {
    $user = User::factory()->create();

    $wallet = $user->wallet()->firstOrFail();
    $wallet->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    $generation = createWalletReservationGeneration($user);

    $service = app(WalletReservationService::class);

    $service->reserve($generation, '2.500000000');
    $service->reserve($generation, '2.500000000');

    $wallet->refresh();

    expect($wallet->reserved_balance)->toBe('2.500000000')
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('rejects a repeated reservation with a different amount', function () {
    $user = User::factory()->create();

    $user->wallet()->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    $generation = createWalletReservationGeneration($user);

    $service = app(WalletReservationService::class);

    $service->reserve($generation, '2.500000000');

    expect(
        fn () => $service->reserve($generation, '3.000000000')
    )->toThrow(RuntimeException::class);
});

it('rejects a reservation when the user has no wallet', function () {
    $user = User::factory()->create();

    // User creation automatically provisions a wallet.
    // Delete it to simulate the missing-wallet condition.
    $user->wallet()->delete();

    $generation = createWalletReservationGeneration($user);

    expect(
        fn () => app(WalletReservationService::class)->reserve(
            $generation,
            '2.500000000'
        )
    )->toThrow(RuntimeException::class);
});

it('rejects zero and malformed reservation amounts', function (string $amount) {
    $user = User::factory()->create();

    $user->wallet()->update([
        'balance' => '10.000000000',
        'reserved_balance' => '0.000000000',
    ]);

    $generation = createWalletReservationGeneration($user);

    expect(
        fn () => app(WalletReservationService::class)->reserve(
            $generation,
            $amount
        )
    )->toThrow(RuntimeException::class);
})->with([
    'zero' => '0.000000000',
    'negative' => '-1.000000000',
    'too many decimal places' => '1.1234567890',
    'scientific notation' => '1e-3',
    'invalid string' => 'invalid',
]);
