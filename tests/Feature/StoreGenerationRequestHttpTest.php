<?php

use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::post('/_test/generation-request', function (
        StoreGenerationRequest $request
    ) {
        return response()->json($request->validated());
    });
});

it('applies default size and quality when omitted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(
        '/_test/generation-request',
        [
            'prompt' => 'A beautiful mountain landscape',
        ]
    );

    $response
        ->assertOk()
        ->assertJsonPath('prompt', 'A beautiful mountain landscape')
        ->assertJsonPath('size', '1024x1024')
        ->assertJsonPath('quality', 'medium');
});

it('preserves explicitly supplied size and quality', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(
        '/_test/generation-request',
        [
            'prompt' => 'A futuristic city',
            'size' => '1536x1024',
            'quality' => 'high',
        ]
    );

    $response
        ->assertOk()
        ->assertJsonPath('size', '1536x1024')
        ->assertJsonPath('quality', 'high');
});
