<?php

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('adds credits to the wallet', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/wallet/purchase', ['amount' => '10', 'token' => (string) Str::uuid()])
        ->assertRedirect();

    expect($user->wallet->refresh()->balance)->toBe('10.000000000');

    $transaction = WalletTransaction::firstOrFail();

    expect($transaction->type)->toBe('credit')
        ->and($transaction->amount)->toBe('10.000000000');
});

it('credits only once when the same purchase is submitted twice', function () {
    $user = User::factory()->create();
    $token = (string) Str::uuid();

    $this->actingAs($user)->post('/wallet/purchase', ['amount' => '5', 'token' => $token]);
    $this->actingAs($user)->post('/wallet/purchase', ['amount' => '5', 'token' => $token]);

    expect($user->wallet->refresh()->balance)->toBe('5.000000000')
        ->and(WalletTransaction::count())->toBe(1);
});

it('rejects amounts that are not an offered pack', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/wallet/purchase', ['amount' => '1000000', 'token' => (string) Str::uuid()])
        ->assertSessionHasErrors('amount');

    expect($user->wallet->refresh()->balance)->toBe('0.000000000');
});

it('requires authentication to buy credits', function () {
    $this->post('/wallet/purchase', ['amount' => '5', 'token' => (string) Str::uuid()])
        ->assertRedirect(route('login'));
});

it('shows the wallet and packs on the dashboard', function () {
    $user = User::factory()->create();
    $user->wallet->update(['balance' => '2.000000000', 'reserved_balance' => '0.500000000']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('wallet.available', '1.500000000')
            ->where('wallet.reserved', '0.500000000')
            ->has('wallet.packs', 4)
        );
});
