<?php

namespace App\Http\Controllers;

use App\Services\Generations\GenerationEstimator;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

class GenerationEstimateController extends Controller
{
    /**
     * Show what a generation is expected to cost before it is submitted.
     */
    public function __invoke(
        Request $request,
        GenerationEstimator $estimator
    ): JsonResponse {
        $input = $request->validate([
            'prompt_length' => ['nullable', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_count' => ['nullable', 'integer', 'min:0', 'max:4'],
        ]);

        try {
            // Price depends only on how long the prompt is, so the client
            // sends the length rather than the prompt text itself.
            $estimate = $estimator->estimate(
                str_repeat('x', (int) ($input['prompt_length'] ?? 0)),
                $input['size'],
                $input['quality'],
                (int) ($input['reference_count'] ?? 0)
            );
        } catch (HttpClientException|UnexpectedValueException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Pricing is temporarily unavailable. Please try again shortly.',
            ], 503);
        }

        return response()->json(['data' => $estimate]);
    }
}
