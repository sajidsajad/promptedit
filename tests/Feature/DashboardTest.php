cat > tests/Feature/DashboardTest.php <<'PHP'
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
