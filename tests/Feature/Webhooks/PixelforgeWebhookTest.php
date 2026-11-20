<?php

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.pixelforge.webhook_secret' => 'whsec_test_secret',
    ]);
});

function pixelforgeWebhookPayload(
    string $eventId = 'evt_test_001',
    string $eventType = 'generation.processing',
    string $generationId = 'gen_test_001',
    string $status = 'processing',
): array {
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $eventType,
        'data' => [
            'object' => [
                'id' => $generationId,
                'object' => 'generation',
                'status' => $status,
            ],
        ],
    ];
}

function signedPixelforgeWebhookHeaders(
    string $rawBody,
    string $eventId = 'evt_test_001',
    string $eventType = 'generation.processing',
): array {
    $timestamp = time();
    $secret = 'whsec_test_secret';

    $signature = hash_hmac(
        'sha256',
        $timestamp.'.'.$rawBody,
        $secret
    );

    return [
        'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
        'HTTP_PIXELFORGE_EVENT_TYPE' => $eventType,
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ];
}

it('rejects a webhook with an invalid signature', function () {
    $payload = pixelforgeWebhookPayload();
    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

    $headers = signedPixelforgeWebhookHeaders($rawBody);
    $headers['Pixelforge-Signature'] = 't='.time().',v1='.str_repeat('a', 64);

    $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    )->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a webhook when required headers are missing', function () {
    $payload = pixelforgeWebhookPayload();
    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $rawBody
    )->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0);
});

it('accepts and persists a valid signed webhook', function () {
    $payload = pixelforgeWebhookPayload();
    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = signedPixelforgeWebhookHeaders($rawBody);

    $response = $this->call(
        'POST',
        '/webhooks/pixelforge',
        [],
        [],
        [],
        $headers,

        $rawBody
    );

    $response->assertAccepted();

    $this->assertDatabaseHas('webhook_events', [
        'event_id' => 'evt_test_001',
        'provider_generation_id' => 'gen_test_001',
        'event_type' => 'generation.processing',
    ]);
});

it('acknowledges duplicate event IDs without storing duplicates', function () {
    $payload = pixelforgeWebhookPayload();
    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = signedPixelforgeWebhookHeaders($rawBody);

    foreach ([1, 2] as $attempt) {
        $headers['Pixelforge-Delivery-Attempt'] = (string) $attempt;

        $this->call(
            'POST',
            '/webhooks/pixelforge',
            [],
            [],
            [],
            $headers, $rawBody
        )->assertAccepted();
    }

    expect(WebhookEvent::where('event_id', 'evt_test_001')->count())
        ->toBe(1);
});
