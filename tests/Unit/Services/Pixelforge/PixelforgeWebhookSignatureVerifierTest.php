<?php

use App\Services\Pixelforge\PixelforgeWebhookSignatureVerifier;

function createWebhookSignature(
    string $body,
    string $secret,
    int $timestamp
): string {
    $signedPayload = $timestamp.'.'.$body;

    $signature = hash_hmac(
        'sha256',
        $signedPayload,
        $secret
    );

    return "t={$timestamp},v1={$signature}";
}

it('accepts a valid webhook signature', function () {
    $body = '{"id":"evt_test123","type":"generation.succeeded"}';
    $secret = 'whsec_test_secret';
    $timestamp = 1795000000;

    $signature = createWebhookSignature(
        $body,
        $secret,
        $timestamp
    );

    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            $body,
            $signature,
            $secret,
            $timestamp
        )
    )->toBeTrue();
});

it('rejects an invalid webhook signature', function () {
    $body = '{"id":"evt_test123"}';
    $secret = 'whsec_test_secret';
    $timestamp = 1795000000;

    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            $body,
            "t={$timestamp},v1=invalid_signature",
            $secret,
            $timestamp
        )
    )->toBeFalse();
});

it('rejects a webhook when its raw body has been modified', function () {
    $originalBody = '{"id":"evt_test123"}';
    $modifiedBody = '{"id": "evt_test123"}';
    $secret = 'whsec_test_secret';
    $timestamp = 1795000000;

    $signature = createWebhookSignature(
        $originalBody,
        $secret,
        $timestamp
    );

    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            $modifiedBody,
            $signature,
            $secret,
            $timestamp
        )
    )->toBeFalse();
});

it('rejects signatures older than five minutes', function () {
    $body = '{"id":"evt_test123"}';
    $secret = 'whsec_test_secret';
    $timestamp = 1795000000;

    $signature = createWebhookSignature(
        $body,
        $secret,
        $timestamp
    );

    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            $body,
            $signature,
            $secret,
            $timestamp + 301
        )
    )->toBeFalse();
});

it('rejects signatures with timestamps too far in the future', function () {
    $body = '{"id":"evt_test123"}';
    $secret = 'whsec_test_secret';
    $timestamp = 1795000000;

    $signature = createWebhookSignature(
        $body,
        $secret,
        $timestamp
    );

    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            $body,
            $signature,
            $secret,
            $timestamp - 301
        )
    )->toBeFalse();
});

it('rejects malformed signature headers', function () {
    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            '{"id":"evt_test123"}',
            'invalid-header',
            'whsec_test_secret',
            1795000000
        )
    )->toBeFalse();
});

it('rejects a missing webhook secret', function () {
    $verifier = new PixelforgeWebhookSignatureVerifier;

    expect(
        $verifier->verify(
            '{"id":"evt_test123"}',
            't=1795000000,v1=abc123',
            null,
            1795000000
        )
    )->toBeFalse();
});
