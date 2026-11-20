<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * Retrieve the current pricing information from Pixelforge.
     */
    public function getPricing(): array
    {
        $response = Http::baseUrl(
            rtrim(config('services.pixelforge.base_url'), '/')
        )
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get('/pricing');

        $response->throw();

        return $response->json();
    }

    /**
     * Retrieve account-level usage for an inclusive UTC date range.
     */
    public function getUsage(
        string $startDate,
        string $endDate
    ): array {
        $response = Http::baseUrl(
            rtrim(config('services.pixelforge.base_url'), '/')
        )
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Submit an asynchronous image generation request.
     */
    public function createGeneration(
        array $payload,
        string $idempotencyKey
    ): array {
        $response = Http::baseUrl(
            rtrim(config('services.pixelforge.base_url'), '/')
        )
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/generations', $payload);

        $response->throw();

        return $response->json();
    }

    public function getGeneration(string $generationId): array
    {
        $response = Http::baseUrl(
            rtrim(config('services.pixelforge.base_url'), '/')
        )
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get("/generations/{$generationId}");

        $response->throw();

        return $response->json();
    }
}
