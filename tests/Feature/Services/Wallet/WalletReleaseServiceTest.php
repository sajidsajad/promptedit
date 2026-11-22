<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletReleaseService;
use App\Services\Wallet\WalletReservationService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWalletReleaseFixture(
    string $balance = '10.000000000',
    string $reservation = '2.500000000',
    bool $reserve = true,
): array {
    $user = User::factory()->create();

    $wallet = $user->wallet()->firstOrFail();
    $wallet->update([
        'balance' => $balance,
        'reserved_balance' => '0.000000000',
    ]);

    $generation = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'wallet-release-test-'.$user->id,
        'provider_idempotency_key' => 'wallet-release-provider-'.$user->id,
        'request_hash' => hash('sha256', 'wallet-release-test-'.$user->id),
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

    if ($reserve) {
        app(WalletReservationService::class)->reserve(
            $generation,
            $reservation
        );
    }

    return [$wallet, $generation];
}

it('releases the full reservation without reducing the total wallet balance', function () {
    [$wallet, $generation] = createWalletReleaseFixture();

    app(WalletReleaseService::class)->release($generation);

    $wallet->refresh();

    expect($wallet->balance)->toBe('10.000000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and(bcsub($wallet->balance, $wallet->reserved_balance, 9))
        ->toBe('10.000000000');

    $transaction = WalletTransaction::query()
        ->where('type', 'release')
        ->firstOrFail();

    expect($transaction->amount)->toBe('2.500000000')
        ->and($transaction->balance_after)->toBe('10.000000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000')
        ->and($transaction->generation_id)->toBe($generation->id);
});

it('does not release the same reservation twice', function () {
    [$wallet, $generation] = createWalletReleaseFixture();

    $service = app(WalletReleaseService::class);

    $service->release($generation);
    $service->release($generation);

    $wallet->refresh();

    expect($wallet->balance)->toBe('10.000000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and(WalletTransaction::query()->where('type', 'release')->count())
        ->toBe(1);
});

it('rejects release when no reservation exists', function () {
    [, $generation] = createWalletReleaseFixture(
        reserve: false
    );

    expect(
        fn () => app(WalletReleaseService::class)->release($generation)
    )->toThrow(RuntimeException::class);
});

it('rejects release after the generation has already been settled', function () {
    [, $generation] = createWalletReleaseFixture();

    app(WalletSettlementService::class)->settle(
        $generation,
        '0.750000000'
    );

    expect(
        fn () => app(WalletReleaseService::class)->release($generation)
    )->toThrow(RuntimeException::class);
});

it('rejects an inconsistent reservation instead of releasing credits twice', function () {
    [$wallet, $generation] = createWalletReleaseFixture();

    // Simulate an inconsistent wallet state.
    $wallet->reserved_balance = '1.000000000';
    $wallet->save();

    expect(
        fn () => app(WalletReleaseService::class)->release($generation)
    )->toThrow(RuntimeException::class);

    expect(
        WalletTransaction::query()->where('type', 'release')->count()
    )->toBe(0);
});

it('records the release with correct ledger balance snapshots', function () {
    [$wallet, $generation] = createWalletReleaseFixture();

    app(WalletReleaseService::class)->release($generation);

    $transaction = WalletTransaction::query()
        ->where('generation_id', $generation->id)
        ->where('type', 'release')
        ->firstOrFail();

    expect($transaction->wallet_id)->toBe($wallet->id)
        ->and($transaction->amount)->toBe('2.500000000')
        ->and($transaction->balance_after)->toBe('10.000000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000')
        ->and($transaction->reference)
        ->toBe("generation:{$generation->id}:release");
});
