<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeUsageReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminReportController extends Controller
{
    private const MAX_RANGE_DAYS = 92;

    /**
     * Operator report: what users were charged, what Pixelforge charged us,
     * and the profit between the two, by UTC completion day and by user.
     *
     * Only succeeded generations are billable, so failed ones never appear.
     */
    public function __invoke(
        Request $request,
        PixelforgeUsageReconciliationService $reconciliation
    ): Response {
        $input = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $end = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $input['end'] ?? now('UTC')->toDateString(),
            'UTC'
        );

        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $input['start'] ?? $end->subDays(29)->toDateString(),
            'UTC'
        );

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) >= self::MAX_RANGE_DAYS) {
            $start = $end->subDays(self::MAX_RANGE_DAYS - 1);
        }

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', 'succeeded')
            ->whereBetween('completed_at', [
                $start->startOfDay(),
                $end->endOfDay(),
            ])
            ->get([
                'id',
                'user_id',
                'completed_at',
                'provider_cost',
                'customer_price',
            ]);

        $byDay = [];
        $byUser = [];
        $byDayUser = [];
        $totals = $this->emptyRow();

        foreach ($generations as $generation) {
            $day = $generation->completed_at->utc()->toDateString();
            $userId = $generation->user_id;

            $this->add($totals, $generation);

            $byDay[$day] ??= $this->emptyRow();
            $this->add($byDay[$day], $generation);

            $byUser[$userId] ??= $this->emptyRow() + [
                'user' => [
                    'id' => $userId,
                    'name' => $generation->user?->name,
                    'email' => $generation->user?->email,
                ],
            ];
            $this->add($byUser[$userId], $generation);

            $key = "{$day}|{$userId}";
            $byDayUser[$key] ??= $this->emptyRow() + [
                'date' => $day,
                'user' => $byUser[$userId]['user'],
            ];
            $this->add($byDayUser[$key], $generation);
        }

        $providerReported = $this->providerReportedCosts(
            $reconciliation,
            $start,
            $end
        );

        $dayRows = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $byDay[$key] ?? $this->emptyRow();

            $dayRows[] = ['date' => $key] + $row + [
                'provider_reported' => $providerReported[$key] ?? null,
            ];
        }

        krsort($byDayUser);

        return Inertia::render('admin/Report', [
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'totals' => $totals,
            'byDay' => array_reverse($dayRows),
            'byUser' => array_values($byUser),
            'byDayUser' => array_values($byDayUser),
            'providerReportAvailable' => $providerReported !== null,
        ]);
    }

    /**
     * Provider-reported cost per day (GET /v1/usage), or null when the
     * Pixelforge usage report cannot be fetched.
     *
     * @return array<string, string>|null
     */
    private function providerReportedCosts(
        PixelforgeUsageReconciliationService $reconciliation,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): ?array {
        try {
            $report = $reconciliation->reconcile(
                $start->toDateString(),
                $end->toDateString()
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return collect($report['days'])
            ->map(fn (array $day) => $day['provider']['provider_cost'])
            ->all();
    }

    /**
     * @return array{generations: int, credit_spend: string, provider_cost: string, profit: string}
     */
    private function emptyRow(): array
    {
        return [
            'generations' => 0,
            'credit_spend' => '0.000000000',
            'provider_cost' => '0.000000000',
            'profit' => '0.000000000',
        ];
    }

    private function add(array &$row, Generation $generation): void
    {
        $spend = bcadd((string) $generation->customer_price, '0', 9);
        $cost = bcadd((string) $generation->provider_cost, '0', 9);

        $row['generations']++;
        $row['credit_spend'] = bcadd($row['credit_spend'], $spend, 9);
        $row['provider_cost'] = bcadd($row['provider_cost'], $cost, 9);
        $row['profit'] = bcsub($row['credit_spend'], $row['provider_cost'], 9);
    }
}
