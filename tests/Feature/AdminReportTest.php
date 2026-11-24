<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function reportGeneration(User $user, string $status, string $cost, string $price, string $completedAt): Generation
{
    return Generation::create([
        'user_id' => $user->id,
        'idempotency_key' => 'k-'.uniqid(),
        'prompt' => 'p',
        'size' => '1024x1024',
        'quality' => 'low',
        'status' => $status,
        'pricing_rates' => [],
        'provider_cost' => $cost,
        'customer_price' => $price,
        'completed_at' => $completedAt,
    ]);
}

function fakeUsage(): void
{
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response([
            'object' => 'usage_report',
            'data' => [
                [
                    'date' => '2026-11-20',
                    'generations' => 2,
                    'input_text_tokens' => 0,
                    'input_image_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'cost_usd' => '0.300000000',
                ],
            ],
        ]),
    ]);
}

it('forbids guests and non-admins', function () {
    $this->get('/admin/report')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get('/admin/report')
        ->assertForbidden();
});

it('reports spend, provider cost and profit per day and per user', function () {
    fakeUsage();

    $admin = User::factory()->create(['is_admin' => true]);
    $ada = User::factory()->create(['name' => 'Ada']);
    $bob = User::factory()->create(['name' => 'Bob']);

    reportGeneration($ada, 'succeeded', '0.100000000', '0.125000000', '2026-11-20 08:00:00');
    reportGeneration($bob, 'succeeded', '0.200000000', '0.250000000', '2026-11-20 23:59:00');
    reportGeneration($ada, 'succeeded', '0.040000000', '0.050000000', '2026-11-21 00:01:00');
    // Failed generations are free and must never appear.
    reportGeneration($ada, 'failed', '0.000000000', '0.000000000', '2026-11-20 09:00:00');

    $this->actingAs($admin)
        ->get('/admin/report?start=2026-11-20&end=2026-11-21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('totals.generations', 3)
            ->where('totals.credit_spend', '0.425000000')
            ->where('totals.provider_cost', '0.340000000')
            ->where('totals.profit', '0.085000000')
            ->has('byDay', 2)
            ->where('byDay.1.date', '2026-11-20')
            ->where('byDay.1.credit_spend', '0.375000000')
            ->where('byDay.1.provider_cost', '0.300000000')
            ->where('byDay.1.provider_reported', '0.300000000')
            ->where('byDay.0.date', '2026-11-21')
            ->has('byUser', 2)
            ->has('byDayUser', 3)
            ->where('providerReportAvailable', true)
        );
});

it('still renders when the Pixelforge usage report is unavailable', function () {
    Http::fake([
        'pixelforge.promptedit.dev/v1/usage*' => Http::response(['error' => 'down'], 503),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('/admin/report?start=2026-11-20&end=2026-11-21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('providerReportAvailable', false));
});

it('grants admin access with the artisan command', function () {
    $user = User::factory()->create();

    $this->artisan('app:make-admin', ['email' => $user->email])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
});
