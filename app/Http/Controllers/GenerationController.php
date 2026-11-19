<?php

namespace App\Http\Controllers;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Requests\StoreGenerationRequest;
use App\Services\Generations\GenerationCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
    public function store(
        StoreGenerationRequest $request,
        GenerationCreationService $service
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [
                    'The Idempotency-Key header is required.',
                ],
            ]);
        }

        if (strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [
                    'The Idempotency-Key header may not exceed 255 characters.',
                ],
            ]);
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

        return response()->json([
            'data' => [
                'id' => $result['generation']->id,
                'status' => $result['generation']->status,
                'replayed' => $result['replayed'],
            ],
        ], 202);
    }
}
