<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication to create a generation', function () {
    $response = $this->postJson('/generations', [
        'prompt' => 'A mountain landscape',
    ]);

    $response->assertUnauthorized();
});

it('requires email verification to create a generation', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->postJson('/generations', [
        'prompt' => 'A mountain landscape',
    ]);

    $response->assertForbidden();
});
