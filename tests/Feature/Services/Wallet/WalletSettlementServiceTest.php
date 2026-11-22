<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletReleaseService;
use App\Services\Wallet\WalletReservationService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createWalletSettlementFixture(
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
        'idempotency_key' => 'wallet-settlement-test-'.$user->id,
        'provider_idempotency_key' => 'wallet-settlement-provider-'.$user->id,
        'request_hash' => hash('sha256', 'wallet-settlement-test-'.$user->id),
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

it('settles the actual charge and releases the full reservation', function () {
    [$wallet, $generation] = createWalletSettlementFixture();

    app(WalletSettlementService::class)->settle(
        $generation,
        '0.750000000'
    );

    $wallet->refresh();

    expect($wallet->balance)->toBe('9.250000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and(bcsub($wallet->balance, $wallet->reserved_balance, 9))
        ->toBe('9.250000000');

    $transaction = WalletTransaction::query()
        ->where('type', 'settle')
        ->firstOrFail();

    expect($transaction->amount)->toBe('0.750000000')
        ->and($transaction->balance_after)->toBe('9.250000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000')
        ->and($transaction->generation_id)->toBe($generation->id);
});

it('does not charge twice when settlement is repeated with the same amount', function () {
    [$wallet, $generation] = createWalletSettlementFixture();

    $service = app(WalletSettlementService::class);

    $service->settle($generation, '0.750000000');
    $service->settle($generation, '0.750000000');

    $wallet->refresh();

    expect($wallet->balance)->toBe('9.250000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and(WalletTransaction::query()->where('type', 'settle')->count())
        ->toBe(1);
});

it('rejects repeated settlement with a different amount', function () {
    [, $generation] = createWalletSettlementFixture();

    $service = app(WalletSettlementService::class);

    $service->settle($generation, '0.750000000');

    expect(
        fn () => $service->settle($generation, '1.000000000')
    )->toThrow(RuntimeException::class);
});

it('rejects settlement when no reservation exists', function () {
    [, $generation] = createWalletSettlementFixture(
        reserve: false
    );

    expect(
        fn () => app(WalletSettlementService::class)->settle(
            $generation,
            '0.750000000'
        )
    )->toThrow(RuntimeException::class);
});

it('rejects settlement when the actual charge exceeds the reservation', function () {
    [, $generation] = createWalletSettlementFixture(
        reservation: '0.500000000'
    );

    expect(
        fn () => app(WalletSettlementService::class)->settle(
            $generation,
            '0.750000000'
        )
    )->toThrow(RuntimeException::class);
});

it('rejects zero and malformed settlement amounts', function (string $amount) {
    [, $generation] = createWalletSettlementFixture();

    expect(
        fn () => app(WalletSettlementService::class)->settle(
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

it('records the settlement with correct ledger balance snapshots', function () {
    [$wallet, $generation] = createWalletSettlementFixture();

    app(WalletSettlementService::class)->settle(
        $generation,
        '0.750000000'
    );

    $transaction = WalletTransaction::query()
        ->where('generation_id', $generation->id)
        ->where('type', 'settle')
        ->firstOrFail();

    expect($transaction->wallet_id)->toBe($wallet->id)
        ->and($transaction->amount)->toBe('0.750000000')
        ->and($transaction->balance_after)->toBe('9.250000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000')
        ->and($transaction->reference)
        ->toBe("generation:{$generation->id}:settle");
});

it('rejects settlement after the reservation has been released', function () {
    [, $generation] = createWalletSettlementFixture(
        reservation: '1.000000000'
    );

    $releaseService = app(WalletReleaseService::class);
    $settlementService = app(WalletSettlementService::class);

    // Release the generation's original reservation.
    $releaseService->release($generation);

    // A released reservation must never be settled afterward.
    expect(fn () => $settlementService->settle(
        $generation,
        '0.500000000'
    ))->toThrow(
        RuntimeException::class,
        'Cannot settle a generation after its reservation has been released.'
    );
});
