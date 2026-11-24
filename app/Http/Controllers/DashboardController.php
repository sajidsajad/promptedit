<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Only allow statuses supported by the generation lifecycle.
        $allowedStatuses = [
            'queued',
            'processing',
            'succeeded',
            'failed',
        ];

        $requestedStatus = $request->query('status');

        // Ignore unsupported status filters.
        $status = in_array($requestedStatus, $allowedStatuses, true)
            ? $requestedStatus
            : null;

        // Calculate billing independently of the history status filter.
        // Only successful generations are billable.
        $billingTotals = Generation::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'succeeded')
            ->selectRaw('
                COUNT(*) as successful_generations,
                COALESCE(SUM(provider_cost), 0) as provider_cost_total,
                COALESCE(SUM(customer_price), 0) as customer_charges_total
            ')
            ->first();

        // Keep monetary calculations at nine decimal places.
        // BCMath avoids PHP floating-point arithmetic.
        $providerCost = bcadd(
            (string) $billingTotals->provider_cost_total,
            '0',
            9
        );

        $customerCharges = bcadd(
            (string) $billingTotals->customer_charges_total,
            '0',
            9
        );

        $markup = bcsub(
            $customerCharges,
            $providerCost,
            9
        );

        $billing = [
            'successful_generations' => (int) $billingTotals->successful_generations,
            'provider_cost_total' => $providerCost,
            'customer_charges_total' => $customerCharges,
            'markup_total' => $markup,
        ];

        // Always scope history to the authenticated user.
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $status,
                fn ($query) => $query->where('status', $status)
            )
            // Use ID as a tie-breaker when timestamps are identical.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Only expose fields needed by the dashboard.
            ->select([
                'id',
                'prompt',
                'size',
                'quality',
                'status',
                'provider_cost',
                'customer_price',
                'estimated_price',
                'image_url',
                'image_url_expires_at',
                'error_code',
                'error_message',
                'started_at',
                'completed_at',
                'created_at',
            ])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Generation $generation) => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'status' => $generation->status,
                'provider_cost' => $generation->provider_cost,
                'customer_price' => $generation->customer_price,
                'estimated_price' => $generation->estimated_price,
                'image_url' => $generation->image_url,
                'image_url_expires_at' => $generation->image_url_expires_at?->toISOString(),
                'error_code' => $generation->error_code,
                'error_message' => $generation->error_message,
                'started_at' => $generation->started_at?->toISOString(),
                'completed_at' => $generation->completed_at?->toISOString(),
                'created_at' => $generation->created_at?->toISOString(),
            ]);

        $wallet = $request->user()->wallet;

        $balance = $wallet?->balance ?? '0.000000000';
        $reserved = $wallet?->reserved_balance ?? '0.000000000';

        return Inertia::render('Dashboard', [
            'wallet' => [
                'balance' => $balance,
                'reserved' => $reserved,
                'available' => bcsub($balance, $reserved, 9),
                'packs' => WalletController::PACKS,
            ],
            'generations' => $generations,
            'filters' => [
                'status' => $status,
            ],
            'billing' => $billing,
        ]);
    }
}
