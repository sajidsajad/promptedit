<?php

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a non-admin user by default', function () {
    $user = User::factory()->create();

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('does not allow mass assignment to promote a user to admin', function () {
    $user = User::factory()->create();

    $user->fill([
        'is_admin' => true,
    ]);

    expect($user->is_admin)->toBeFalse();
});

it('allows each user to have one wallet', function () {
    $user = User::factory()->create();

    $wallet = $user->wallet()->create();

    expect($user->fresh()->wallet->is($wallet))->toBeTrue()
        ->and($wallet->balance)->toBe('0.000000000')
        ->and($wallet->reserved_balance)->toBe('0.000000000');
});

it('preserves nine decimal places in wallet balances', function () {
    $user = User::factory()->create();

    $wallet = $user->wallet()->create([
        'balance' => '12.345678901',
        'reserved_balance' => '1.234567890',
    ]);

    $wallet->refresh();

    expect($wallet->balance)->toBe('12.345678901')
        ->and($wallet->reserved_balance)->toBe('1.234567890');
});

it('records wallet transactions with balance snapshots', function () {
    $user = User::factory()->create();

    $wallet = $user->wallet()->create([
        'balance' => '10.000000000',
        'reserved_balance' => '2.000000000',
    ]);

    $transaction = $wallet->transactions()->create([
        'type' => 'credit',
        'amount' => '10.000000000',
        'balance_after' => '10.000000000',
        'reserved_balance_after' => '0.000000000',
        'reference' => 'wallet-test:credit:001',
        'description' => 'Initial test credit',
    ]);

    expect($wallet->fresh()->transactions)->toHaveCount(1)
        ->and($transaction->wallet->is($wallet))->toBeTrue()
        ->and($transaction->amount)->toBe('10.000000000')
        ->and($transaction->balance_after)->toBe('10.000000000')
        ->and($transaction->reserved_balance_after)->toBe('0.000000000');
});

it('rejects duplicate wallet transaction references', function () {
    $user = User::factory()->create();
    $wallet = $user->wallet()->create();

    $attributes = [
        'type' => 'credit',
        'amount' => '5.000000000',
        'balance_after' => '5.000000000',
        'reserved_balance_after' => '0.000000000',
        'reference' => 'wallet-test:duplicate:001',
        'description' => 'Test credit',
    ];

    $wallet->transactions()->create($attributes);

    expect(fn () => $wallet->transactions()->create($attributes))
        ->toThrow(QueryException::class);
});

it('prevents a user from having multiple wallets', function () {
    $user = User::factory()->create();

    $user->wallet()->create();

    expect(fn () => Wallet::create([
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});
