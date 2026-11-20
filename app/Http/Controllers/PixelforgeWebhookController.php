<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Pixelforge\PixelforgeWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PixelforgeWebhookSignatureVerifier $signatureVerifier
    ): JsonResponse {
        $rawBody = $request->getContent();

        $signature = $request->header('Pixelforge-Signature');
        $eventId = $request->header('Pixelforge-Event-Id');
        $eventType = $request->header('Pixelforge-Event-Type');
        $deliveryAttempt = $request->header('Pixelforge-Delivery-Attempt');

        if (
            ! is_string($signature)
            || ! $signatureVerifier->verify(
                $rawBody,
                $signature,
                config('services.pixelforge.webhook_secret')
            )
        ) {
            return response()->json([
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        if (
            ! is_string($eventId)
            || ! str_starts_with($eventId, 'evt_')
            || ! is_string($eventType)
            || ! is_numeric($deliveryAttempt)
            || (int) $deliveryAttempt < 1
        ) {
            return response()->json([
                'message' => 'Invalid webhook headers.',
            ], 400);
        }

        try {
            $payload = json_decode(
                $rawBody,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return response()->json([
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $validator = Validator::make($payload, [
            'id' => ['required', 'string', 'starts_with:evt_'],
            'object' => ['required', 'in:event'],
            'type' => [
                'required',
                'in:generation.processing,generation.succeeded,generation.failed,ping',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid webhook payload.',
                'errors' => $validator->errors(),
            ], 400);
        }

        if (
            $payload['id'] !== $eventId
            || $payload['type'] !== $eventType
        ) {
            return response()->json([
                'message' => 'Webhook headers do not match the payload.',
            ], 400);
        }

        if ($eventType === 'ping') {
            return response()->json(['received' => true], 202);
        }

        $generation = $payload['data']['object'] ?? null;

        if (
            ! is_array($generation)
            || ! is_string($generation['id'] ?? null)
            || ! str_starts_with($generation['id'], 'gen_')
            || ! in_array(
                $generation['status'] ?? null,
                ['queued', 'processing', 'succeeded', 'failed'],
                true
            )
        ) {
            return response()->json([
                'message' => 'Invalid generation payload.',
            ], 400);
        }

        WebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'provider_generation_id' => $generation['id'],
                'event_type' => $eventType,
                'payload' => $payload,
            ]
        );

        return response()->json(['received' => true], 202);
    }
}
