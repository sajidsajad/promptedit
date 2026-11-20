<?php

namespace App\Services\Pixelforge;

use DateTimeImmutable;
use InvalidArgumentException;

class PixelforgeUsageService
{
    private const MAX_RANGE_DAYS = 92;

    public function __construct(
        private readonly PixelforgeClient $client
    ) {}

    /**
     * Retrieve provider usage for an inclusive date range.
     */
    public function getUsage(
        string $startDate,
        string $endDate
    ): array {
        $start = $this->parseDate($startDate, 'start date');
        $end = $this->parseDate($endDate, 'end date');

        if ($start > $end) {
            throw new InvalidArgumentException(
                'The start date must not be after the end date.'
            );
        }

        // Include both the start and end dates.
        $rangeDays = (int) $start->diff($end)->days + 1;

        if ($rangeDays > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(
                'The usage date range cannot exceed 92 inclusive days.'
            );
        }

        return $this->client->getUsage(
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
    }

    /**
     * Parse a strict YYYY-MM-DD date.
     */
    private function parseDate(
        string $value,
        string $field
    ): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false ||
            ($errors !== false && (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )) ||
            $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                "Invalid {$field}. Expected YYYY-MM-DD."
            );
        }

        return $date;
    }
}
