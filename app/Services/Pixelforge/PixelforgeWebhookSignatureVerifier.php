<?php

namespace App\Services\Pixelforge;

class PixelforgeWebhookSignatureVerifier
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public function verify(
        string $rawBody,
        string $signatureHeader,
        ?string $secret,
        ?int $now = null
    ): bool {
        // A missing or empty secret means verification cannot be trusted.
        if (! is_string($secret) || trim($secret) === '') {
            return false;
        }

        // Require the exact documented signature format:
        // t=UNIX_TIMESTAMP,v1=HEX_SIGNATURE
        if (! preg_match(
            '/\At=(\d+),v1=([a-f0-9]{64})\z/i',
            trim($signatureHeader),
            $matches
        )) {
            return false;
        }

        $timestamp = filter_var(
            $matches[1],
            FILTER_VALIDATE_INT
        );

        if ($timestamp === false || $timestamp <= 0) {
            return false;
        }

        // In production, use the server's current time.
        // The optional argument makes timestamp tests deterministic.
        $currentTime = $now ?? time();

        // Reject signatures more than 300 seconds old or in the future.
        if (abs($currentTime - $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            return false;
        }

        // Sign the exact raw body. Do not decode or re-encode JSON.
        $signedPayload = $timestamp.'.'.$rawBody;

        $expectedSignature = hash_hmac(
            'sha256',
            $signedPayload,
            $secret
        );

        // hash_equals prevents timing-based signature comparison attacks.
        return hash_equals(
            $expectedSignature,
            strtolower($matches[2])
        );
    }
}
