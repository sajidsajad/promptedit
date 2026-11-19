<?php

namespace App\Services\Generations;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationCreationService
{
    public function create(
        User $user,
        array $payload,
        string $idempotencyKey
    ): array {
        $normalizedPayload = [
            'prompt' => $payload['prompt'],
            'size' => $payload['size'] ?? '1024x1024',
            'quality' => $payload['quality'] ?? 'medium',
            'reference_images' => $payload['reference_images'] ?? [],
            'metadata' => $payload['metadata'] ?? [],
        ];

        // Sort associative keys recursively, but preserve list ordering.
        $canonicalPayload = $this->canonicalize($normalizedPayload);

        $requestHash = hash(
            'sha256',
            json_encode(
                $canonicalPayload,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            )
        );

        try {
            return DB::transaction(function () use (
                $user,
                $normalizedPayload,
                $idempotencyKey,
                $requestHash
            ) {
                $existing = Generation::where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->resolveExisting($existing, $requestHash);
                }

                $generation = Generation::create([
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'provider_idempotency_key' => (string) Str::uuid(),
                    'request_hash' => $requestHash,
                    'prompt' => $normalizedPayload['prompt'],
                    'size' => $normalizedPayload['size'],
                    'quality' => $normalizedPayload['quality'],
                    'reference_images' => $normalizedPayload['reference_images'],
                    'metadata' => $normalizedPayload['metadata'],
                    'pricing_rates' => [],
                    'status' => 'queued',
                ]);

                return [
                    'generation' => $generation,
                    'replayed' => false,
                ];
            });
        } catch (QueryException $exception) {
            // Recover only from the user's idempotency unique constraint.
            if (! $this->isIdempotencyUniqueViolation($exception)) {
                throw $exception;
            }

            // The failed transaction has already rolled back here.
            $existing = Generation::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            // If no matching row exists, this was not our expected race.
            if (! $existing) {
                throw $exception;
            }

            return $this->resolveExisting($existing, $requestHash);
        }
    }

    private function resolveExisting(
        Generation $generation,
        string $requestHash
    ): array {
        if (
            ! is_string($generation->request_hash) ||
            ! hash_equals($generation->request_hash, $requestHash)
        ) {
            throw new IdempotencyConflictException(
                'This idempotency key was already used with a different request.'
            );
        }

        return [
            'generation' => $generation,
            'replayed' => true,
        ];
    }

    private function isIdempotencyUniqueViolation(
        QueryException $exception
    ): bool {
        $errorInfo = $exception->errorInfo ?? [];

        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        return $sqlState === '23000'
            && $driverErrorCode === 1062
            && str_contains(
                $message,
                'generations_user_idempotency_unique'
            );
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item)
                    ? $this->canonicalize($item)
                    : $item,
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
