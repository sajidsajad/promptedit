<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class PixelforgeUsageReconciliationService
{
    private const METRICS = [
        'generations',
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    public function __construct(
        private readonly PixelforgeUsageService $usageService
    ) {}

    /**
     * Compare provider usage with locally recorded successful generations.
     *
     * This method is read-only. It never modifies generation or billing data.
     */
    public function reconcile(
        string $startDate,
        string $endDate
    ): array {
        $start = $this->parseDate($startDate, 'start date');
        $end = $this->parseDate($endDate, 'end date');

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException(
                'The start date must not be after the end date.'
            );
        }

        $providerReport = $this->usageService->getUsage(
            $start->toDateString(),
            $end->toDateString()
        );

        $providerDays = [];

foreach ($providerReport['data'] ?? [] as $record) {
    if (! is_array($record)) {
        throw new InvalidArgumentException(
            'Provider usage contains an invalid daily record.'
        );
    }

    $date = $this->parseProviderDate($record['date'] ?? null);

    $providerDays[$date] = [
        'generations' => $this->nonNegativeInteger(
            $record['generations'] ?? null,
            'generations'
        ),
        'input_text_tokens' => $this->nonNegativeInteger(
            $record['input_text_tokens'] ?? null,
            'input_text_tokens'
        ),
        'input_image_tokens' => $this->nonNegativeInteger(
            $record['input_image_tokens'] ?? null,
            'input_image_tokens'
        ),
        'output_tokens' => $this->nonNegativeInteger(
            $record['output_tokens'] ?? null,
            'output_tokens'
        ),
        'reasoning_tokens' => $this->nonNegativeInteger(
            $record['reasoning_tokens'] ?? null,
            'reasoning_tokens'
        ),
        'provider_cost' => $this->normalizeMoney(
            $record['cost_usd'] ?? '0.000000000'
        ),
    ];
}
        $localDays = $this->getLocalUsage(
            $start,
            $end
        );

        $days = [];
        $matched = true;

        for (
            $date = $start;
            $date->lessThanOrEqualTo($end);
            $date = $date->addDay()
        ) {
            $key = $date->toDateString();

            $provider = $providerDays[$key] ?? $this->emptyMetrics();
            $local = $localDays[$key] ?? $this->emptyMetrics();

            $differences = [];

            foreach (self::METRICS as $metric) {
                $differences[$metric] =
                    $provider[$metric] - $local[$metric];
            }

            $differences['provider_cost'] = bcsub(
                $provider['provider_cost'],
                $local['provider_cost'],
                9
            );

            $dayMatched = collect($differences)
                ->every(fn ($difference) => $difference === 0
                    || $difference === '0.000000000');

            if (! $dayMatched) {
                $matched = false;
            }

            $days[$key] = [
                'matched' => $dayMatched,
                'provider' => $provider,
                'local' => $local,
                'differences' => $differences,
            ];
        }

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'matched' => $matched,
            'days' => $days,
        ];
    }

    /**
     * Aggregate successful local generations by UTC completion date.
     */
    private function getLocalUsage(
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $generations = Generation::query()
            ->where('status', 'succeeded')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                $start->startOfDay()->utc(),
                $end->endOfDay()->utc(),
            ])
            ->get([
                'completed_at',
                'usage',
                'provider_cost',
            ]);

        $daily = [];

        foreach ($generations as $generation) {
            $date = CarbonImmutable::parse(
                $generation->completed_at
            )->utc()->toDateString();

            $daily[$date] ??= $this->emptyMetrics();

            $usage = $generation->usage ?? [];

            $daily[$date]['generations']++;

            foreach ([
                'input_text_tokens',
                'input_image_tokens',
                'output_tokens',
                'reasoning_tokens',
            ] as $metric) {
                $daily[$date][$metric] += (int) ($usage[$metric] ?? 0);
            }

            $daily[$date]['provider_cost'] = bcadd(
                $daily[$date]['provider_cost'],
                $this->normalizeMoney($generation->provider_cost),
                9
            );
        }

        return $daily;
    }

    private function emptyMetrics(): array
    {
        return [
            'generations' => 0,
            'input_text_tokens' => 0,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'provider_cost' => '0.000000000',
        ];
    }

/**
 * Validate a provider date without accepting normalized invalid dates.
 */
private function parseProviderDate(mixed $value): string
{
    if (
        ! is_string($value) ||
        ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
    ) {
        throw new InvalidArgumentException(
            'Provider usage contains an invalid date.'
        );
    }

    $date = CarbonImmutable::createFromFormat(
        '!Y-m-d',
        $value,
        'UTC'
    );

    $errors = CarbonImmutable::getLastErrors();

    if (
        $date === false ||
        ($errors !== false && (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        )) ||
        $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException(
            'Provider usage contains an invalid date.'
        );
    }

    return $value;
}

/**
 * Accept only non-negative integer token and generation counts.
 */
private function nonNegativeInteger(
    mixed $value,
    string $field
): int {
    if (
        ! is_int($value) && (
            ! is_string($value) ||
            ! preg_match('/^\d+$/', $value)
        )
    ) {
        throw new InvalidArgumentException(
            "Provider usage contains an invalid {$field} count."
        );
    }

    $integer = (int) $value;

    if ($integer < 0) {
        throw new InvalidArgumentException(
            "Provider usage contains a negative {$field} count."
        );
    }

    return $integer;
}

    private function normalizeMoney(string $amount): string
    {
        if (! preg_match('/^\d+(\.\d{1,9})?$/', $amount)) {
            throw new InvalidArgumentException(
                'Usage report contains an invalid monetary amount.'
            );
        }

        return bcadd($amount, '0', 9);
    }

    private function parseDate(
        string $value,
        string $field
    ): CarbonImmutable {
        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            'UTC'
        );

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(
                "Invalid {$field}. Expected YYYY-MM-DD."
            );
        }

        return $date;
    }
}
