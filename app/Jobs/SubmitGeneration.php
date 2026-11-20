<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationProviderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum attempts for temporary provider failures.
     */
    public int $tries = 5;

    /**
     * Delay between retry attempts, in seconds.
     */
    public array $backoff = [5, 15, 30, 60, 120];

    /**
     * Maximum execution time for one attempt.
     */
    public int $timeout = 45;

    public function __construct(
        public readonly int $generationId
    ) {}

    /**
     * Submit the generation to Pixelforge.
     */
    public function handle(
        GenerationProviderService $providerService
    ): void {
        $generation = Generation::find($this->generationId);

        // The generation may have been deleted before the job ran.
        if (! $generation) {
            return;
        }

        // Never submit a generation that already has a provider ID.
        if ($generation->pixelforge_id !== null) {
            return;
        }

        try {
            $providerService->submit($generation);
        } catch (RequestException $exception) {
            $response = $exception->response;

            $status = $response?->status();
            $body = $response?->json();

            $errorCode = is_array($body)
                ? ($body['error']['code'] ?? $body['code'] ?? null)
                : null;

            $errorMessage = is_array($body)
                ? ($body['error']['message'] ?? $body['message'] ?? null)
                : null;

            // These responses indicate that retrying the same request
            // will not solve the problem.
            $permanentFailure = in_array(
                $status,
                [400, 401, 403, 404, 409, 422],
                true
            ) || (
                $status === 429
                && $errorCode === 'daily_limit_exceeded'
            );

            if (! $permanentFailure) {
                // Rate limits, concurrency limits, overloads and server
                // errors are temporary. Let Laravel retry the job.
                throw $exception;
            }

            $this->markPermanentFailure(
                $errorCode ?: 'provider_request_rejected',
                $errorMessage ?: 'Pixelforge rejected the generation request.'
            );
        }
    }

    /**
     * Persist a permanent provider rejection without charging the user.
     */
    private function markPermanentFailure(
        string $errorCode,
        string $errorMessage
    ): void {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->pixelforge_id !== null) {
            return;
        }

        // Never overwrite a terminal state.
        if (in_array($generation->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $generation->status = 'failed';
        $generation->error_code = mb_substr($errorCode, 0, 255);
        $generation->error_message = mb_substr($errorMessage, 0, 5000);
        $generation->provider_cost = '0.000000000';
        $generation->customer_price = '0.000000000';
        $generation->usage = null;
        $generation->completed_at = now();
        $generation->save();
    }

    /**
     * Record exhausted retries for investigation.
     *
     * A timeout may mean Pixelforge accepted the request but our application
     * never received its response. Therefore, do not mark it failed here.
     * The stable provider idempotency key allows a safe later retry.
     */
    public function failed(Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if (
            ! $generation
            || $generation->pixelforge_id !== null
            || in_array($generation->status, ['succeeded', 'failed'], true)
        ) {
            return;
        }

        $generation->error_code = 'submission_retry_exhausted';
        $generation->error_message = mb_substr(
            $exception->getMessage(),
            0,
            5000
        );
        $generation->save();
    }
}
