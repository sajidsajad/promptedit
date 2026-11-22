<?php

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to credit wallets in production', function () {
    $user = User::factory()->create();

    $this->app['env'] = 'production';

    $this->artisan('wallet:credit', [
        'user_id' => $user->id,
        'amount' => '10.000000000',
        'reference' => 'dev-funding:production-test',
    ])
        ->expectsOutput('Wallet funding is only available in local or testing environments.')
        ->assertFailed();

    expect($user->wallet()->firstOrFail()->balance)
        ->toBe('0.000000000')
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('rejects an unknown user', function () {
    $this->artisan('wallet:credit', [
        'user_id' => 999999,
        'amount' => '10.000000000',
        'reference' => 'dev-funding:unknown-user',
    ])
        ->expectsOutput('User not found.')
        ->assertFailed();

    expect(WalletTransaction::query()->count())->toBe(0);
});

it('does not credit the wallet when confirmation is declined', function () {
    $user = User::factory()->create();

    $this->artisan('wallet:credit', [
        'user_id' => $user->id,
        'amount' => '10.000000000',
        'reference' => 'dev-funding:declined',
    ])
        ->expectsConfirmation(
            'Confirm adding 10.000000000 USD credits to user '.$user->id.'?',
            'no',
        )
        ->expectsOutput('Wallet funding cancelled.')
        ->assertSuccessful();

    expect($user->wallet()->firstOrFail()->balance)
        ->toBe('0.000000000')
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('credits the wallet after explicit confirmation', function () {
    $user = User::factory()->create();

    $this->artisan('wallet:credit', [
        'user_id' => $user->id,
        'amount' => '10.000000000',
        'reference' => 'dev-funding:confirmed',
    ])
        ->expectsConfirmation(
            'Confirm adding 10.000000000 USD credits to user '.$user->id.'?',
            'yes',
        )
        ->expectsOutput('Wallet credited successfully.')
        ->assertSuccessful();

    $wallet = $user->wallet()->firstOrFail();

    expect($wallet->fresh()->balance)
        ->toBe('10.000000000')
        ->and($wallet->transactions)->toHaveCount(1);

    $transaction = WalletTransaction::query()->firstOrFail();

    expect($transaction->type)->toBe('credit')
        ->and($transaction->amount)->toBe('10.000000000')
        ->and($transaction->reference)->toBe('dev-funding:confirmed');
});
