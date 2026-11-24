<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Generations\GenerationCreationService;
use App\Services\Generations\GenerationEstimator;
use App\Services\Generations\GenerationProviderService;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class GenerationController extends Controller
{
    /**
     * Create a generation for the authenticated user.
     */
    public function store(
        StoreGenerationRequest $request,
        GenerationCreationService $service,
        GenerationEstimator $estimator
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key header is required.',
            ]);
        }

        if (strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key may not exceed 255 characters.',
            ]);
        }

        $insufficient = $this->insufficientCreditsResponse(
            $request,
            $estimator,
            $idempotencyKey
        );

        if ($insufficient !== null) {
            return $insufficient;
        }

        try {
            $result = $service->create(
                $request->user(),
                $request->validated(),
                $idempotencyKey
            );
        } catch (IdempotencyConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        if (! $result['replayed']) {
            SubmitGeneration::dispatch($result['generation']->id);
        }

        return response()->json([
            'data' => [
                'id' => $result['generation']->id,
                'status' => $result['generation']->status,
                'replayed' => $result['replayed'],
            ],
        ], 202);
    }

    /**
     * Return the status and display information for an owned generation.
     *
     * If the generation succeeded and its signed image URL has expired,
     * attempt to refresh that URL without changing billing or lifecycle data.
     */
    public function show(
        Request $request,
        int $generation,
        GenerationProviderService $providerService
    ): JsonResponse {
        // Scope the lookup to the authenticated user.
        // A generation belonging to another user returns 404.
        $record = Generation::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($generation);

        try {
            $record = $providerService->refreshImageUrl($record);
        } catch (RequestException|UnexpectedValueException $exception) {
            // Image refresh is auxiliary. A provider failure must not make
            // the generation status endpoint unavailable.
            report($exception);

            $record->refresh();
        }

        return response()->json([
            'data' => [
                'id' => $record->id,
                'prompt' => $record->prompt,
                'size' => $record->size,
                'quality' => $record->quality,
                'status' => $record->status,
                'usage' => $record->usage,
                'estimated_price' => $record->estimated_price,
                'provider_cost' => $record->provider_cost,
                'customer_price' => $record->customer_price,
                'image_url' => $record->image_url,
                'image_url_expires_at' => $record->image_url_expires_at,
                'error_code' => $record->error_code,
                'error_message' => $record->error_message,
                'started_at' => $record->started_at,
                'completed_at' => $record->completed_at,
                'created_at' => $record->created_at,
            ],
        ]);
    }

    /**
     * Refuse a new generation the user cannot afford, before anything is
     * created or reserved.
     *
     * A replay of an existing idempotency key is never blocked, and a
     * pricing outage does not block either: the submission job re-checks
     * the wallet when it reserves credits.
     */
    private function insufficientCreditsResponse(
        StoreGenerationRequest $request,
        GenerationEstimator $estimator,
        string $idempotencyKey
    ): ?JsonResponse {
        $user = $request->user();

        $isReplay = Generation::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        if ($isReplay) {
            return null;
        }

        try {
            $estimate = $estimator->estimate(
                $request->validated('prompt'),
                $request->validated('size'),
                $request->validated('quality'),
                count($request->validated('reference_images') ?? [])
            );
        } catch (HttpClientException|UnexpectedValueException $exception) {
            report($exception);

            return null;
        }

        $wallet = $user->wallet;

        $available = $wallet
            ? bcsub($wallet->balance, $wallet->reserved_balance, 9)
            : '0.000000000';

        if (bccomp($available, $estimate['maximum_price'], 9) >= 0) {
            return null;
        }

        return response()->json([
            'message' => 'You do not have enough credits for this generation. Add credits and try again.',
            'code' => 'insufficient_credits',
            'required' => $estimate['maximum_price'],
            'available' => $available,
        ], 402);
    }
}
