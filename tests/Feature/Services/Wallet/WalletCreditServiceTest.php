<?php

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('credits a wallet and records the ledger transaction', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    app(WalletCreditService::class)->credit(
        $wallet,
        '10.250000000',
        'dev-funding:user-1:initial',
        'Local development test credits',
    );

    $wallet->refresh();

    expect($wallet->balance)->toBe('10.250000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000')
        ->and($wallet->transactions)->toHaveCount(1);

    $transaction = WalletTransaction::query()->firstOrFail();

    expect($transaction->type)->toBe('credit')
        ->and($transaction->amount)->toBe('10.250000000')
        ->and($transaction->balance_after)->toBe('10.250000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000')
        ->and($transaction->reference)->toBe('dev-funding:user-1:initial')
        ->and($transaction->generation_id)->toBeNull();
});

it('preserves reserved credits when funding a wallet', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $wallet->update([
        'balance' => '10.000000000',
        'reserved_balance' => '3.000000000',
    ]);

    app(WalletCreditService::class)->credit(
        $wallet,
        '5.000000000',
        'dev-funding:user-1:additional',
        'Additional development credits',
    );

    $wallet->refresh();

    expect($wallet->balance)->toBe('15.000000000')
        ->and($wallet->reserved_balance)->toBe('3.000000000')
        ->and(bcsub($wallet->balance, $wallet->reserved_balance, 9))
        ->toBe('12.000000000');
});

it('does not credit the same reference twice', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $service = app(WalletCreditService::class);

    $service->credit(
        $wallet,
        '10.000000000',
        'dev-funding:user-1:retry',
        'Development credits',
    );

    $service->credit(
        $wallet,
        '10.000000000',
        'dev-funding:user-1:retry',
        'Development credits',
    );

    expect($wallet->fresh()->balance)->toBe('10.000000000')
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('rejects reuse of a credit reference with a different amount', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $service = app(WalletCreditService::class);

    $service->credit(
        $wallet,
        '10.000000000',
        'dev-funding:user-1:conflict',
        'Development credits',
    );

    expect(fn () => $service->credit(
        $wallet,
        '20.000000000',
        'dev-funding:user-1:conflict',
        'Development credits',
    ))->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)->toBe('10.000000000')
        ->and(WalletTransaction::query()->count())->toBe(1);
});

it('rejects a reference belonging to another wallet', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $service = app(WalletCreditService::class);

    $service->credit(
        $firstUser->wallet()->firstOrFail(),
        '10.000000000',
        'dev-funding:shared-reference',
        'Development credits',
    );

    expect(fn () => $service->credit(
        $secondUser->wallet()->firstOrFail(),
        '10.000000000',
        'dev-funding:shared-reference',
        'Development credits',
    ))->toThrow(RuntimeException::class);

    expect($secondUser->wallet()->firstOrFail()->balance)
        ->toBe('0.000000000');
});

it('rejects zero and malformed credit amounts', function (string $amount) {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    expect(fn () => app(WalletCreditService::class)->credit(
        $wallet,
        $amount,
        'dev-funding:invalid',
        'Invalid test credit',
    ))->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)->toBe('0.000000000')
        ->and(WalletTransaction::query()->count())->toBe(0);
})->with([
    'zero' => '0.000000000',
    'negative' => '-1.000000000',
    'too many decimal places' => '1.1234567890',
    'scientific notation' => '1e-3',
    'invalid string' => 'invalid',
]);

it('rejects an empty or oversized reference', function (string $reference) {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    expect(fn () => app(WalletCreditService::class)->credit(
        $wallet,
        '10.000000000',
        $reference,
        'Development credits',
    ))->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)->toBe('0.000000000');
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'oversized' => str_repeat('a', 192),
]);

it('rejects an amount exceeding the wallet decimal capacity', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    expect(fn () => app(WalletCreditService::class)->credit(
        $wallet,
        '1000000000.000000000',
        'dev-funding:overflow',
        'Development credits',
    ))->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)->toBe('0.000000000');
});

it('rejects a credit that would overflow the existing wallet balance', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $wallet->update([
        'balance' => '999999999.000000000',
    ]);

    expect(fn () => app(WalletCreditService::class)->credit(
        $wallet,
        '1.000000000',
        'dev-funding:balance-overflow',
        'Overflow test',
    ))->toThrow(RuntimeException::class);

    expect($wallet->fresh()->balance)
        ->toBe('999999999.000000000')
        ->and(WalletTransaction::query()->count())->toBe(0);
});
