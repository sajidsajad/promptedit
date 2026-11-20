
<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('generations.data')
            ->has('billing')
        );
});

test('dashboard displays only the authenticated users generations', function () {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();

    $ownGeneration = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'own-generation',
        'prompt' => 'A mountain landscape',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'pricing_rates' => ['output_tokens' => '40.00'],
        'provider_cost' => '0.053215000',
        'customer_price' => '0.066518750',
    ]);

    Generation::create([
        'user_id' => $anotherUser->id,
        'idempotency_key' => 'another-users-generation',
        'prompt' => 'A private image',
        'size' => '1024x1024',
        'quality' => 'high',
        'status' => 'succeeded',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('generations.data', 1)
        ->where('generations.data.0.id', $ownGeneration->id)
        ->where('generations.data.0.prompt', 'A mountain landscape')
        ->where('generations.data.0.status', 'succeeded')
        ->where('generations.data.0.customer_price', '0.066518750')
    );
});

test('dashboard filters generations by status', function () {
    $user = User::factory()->create();

    Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'successful-generation',
        'prompt' => 'A successful image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    $failedGeneration = Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'failed-generation',
        'prompt' => 'A failed image',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'failed',
        'pricing_rates' => ['output_tokens' => '40.00'],
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'failed']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $failedGeneration->id)
            ->where('generations.data.0.status', 'failed')
        );
});

test('dashboard paginates generations with twenty records per page', function () {
    $user = User::factory()->create();

    foreach (range(1, 21) as $number) {
        Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => "pagination-{$number}",
            'prompt' => "Generation {$number}",
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => 'queued',
            'pricing_rates' => ['output_tokens' => '40.00'],
        ]);
    }

    $firstPage = $this->actingAs($user)
        ->get(route('dashboard'));

    $firstPage->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('generations.data', 20)
        );

    $secondPage = $this->get(route('dashboard', ['page' => 2]));

    $secondPage->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('generations.data', 1)
        );
});

test('billing summary includes only the authenticated users successful generations', function () {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();

    foreach ([
        [
            'key' => 'successful-one',
            'status' => 'succeeded',
            'provider_cost' => '0.100000000',
            'customer_price' => '0.125000000',
        ],
        [
            'key' => 'successful-two',
            'status' => 'succeeded',
            'provider_cost' => '0.200000000',
            'customer_price' => '0.250000000',
        ],
        [
            'key' => 'failed-one',
            'status' => 'failed',
            'provider_cost' => '0.900000000',
            'customer_price' => '1.125000000',
        ],
        [
            'key' => 'queued-one',
            'status' => 'queued',
            'provider_cost' => '0.800000000',
            'customer_price' => '1.000000000',
        ],
    ] as $record) {
        Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => $record['key'],
            'prompt' => $record['key'],
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => $record['status'],
            'pricing_rates' => ['output_tokens' => '40.00'],
            'provider_cost' => $record['provider_cost'],
            'customer_price' => $record['customer_price'],
        ]);
    }

    Generation::create([
        'user_id' => $anotherUser->id,
        'idempotency_key' => 'other-user-success',
        'prompt' => 'Another users generation',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'succeeded',
        'pricing_rates' => ['output_tokens' => '40.00'],
        'provider_cost' => '5.000000000',
        'customer_price' => '6.250000000',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['status' => 'failed']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'failed')
            ->where('billing.successful_generations', 2)
            ->where('billing.provider_cost_total', '0.300000000')
            ->where('billing.customer_charges_total', '0.375000000')
            ->where('billing.markup_total', '0.075000000')
        );
});

test('billing summary returns exact zero amounts when there are no successful generations', function () {
    $user = User::factory()->create();

    Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'only-failed-generation',
        'prompt' => 'A failed generation',
        'size' => '1024x1024',
        'quality' => 'medium',
        'status' => 'failed',
        'pricing_rates' => ['output_tokens' => '40.00'],
        'provider_cost' => '0.000000000',
        'customer_price' => '0.000000000',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billing.successful_generations', 0)
            ->where('billing.provider_cost_total', '0.000000000')
            ->where('billing.customer_charges_total', '0.000000000')
            ->where('billing.markup_total', '0.000000000')
        );
});
