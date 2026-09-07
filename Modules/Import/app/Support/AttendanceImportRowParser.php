<?php

namespace Modules\Import\Support;

use Carbon\Carbon;
use Modules\Import\DTOs\ParsedImportRow;
use Modules\Performance\Models\PerformanceScore;

/**
 * Pure, DB-free validation of one CSV row. Employee-existence is checked
 * separately by the job (it needs a bulk DB lookup); everything else about
 * the row's own shape is decided here so it's unit-testable without a DB.
 */
class AttendanceImportRowParser
{
    /**
     * @param  array<string, string|null>  $row  keyed by header: employee_email, date, check_in, check_out, performance
     */
    public function parse(int $rowNumber, array $row): ParsedImportRow
    {
        $email = trim((string) ($row['employee_email'] ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ParsedImportRow::invalid($rowNumber, $row, 'employee email is missing or invalid');
        }

        $date = trim((string) ($row['date'] ?? ''));
        $checkIn = trim((string) ($row['check_in'] ?? ''));
        $checkOut = trim((string) ($row['check_out'] ?? ''));
        $performance = trim((string) ($row['performance'] ?? ''));

        if ($date === '' && $checkIn === '' && $checkOut === '' && $performance === '') {
            return ParsedImportRow::invalid($rowNumber, $row, 'row has no attendance or performance data');
        }

        if ($date === '' && $performance !== '') {
            // This CSV schema has no standalone "month" column - a score can
            // only be attributed to the month of the row's own date.
            return ParsedImportRow::invalid($rowNumber, $row, 'performance requires a date');
        }

        $parsedDate = null;
        if ($date !== '') {
            $parsedDate = $this->parseDate($date);
            if ($parsedDate === null) {
                return ParsedImportRow::invalid($rowNumber, $row, 'invalid date');
            }
        }

        [$parsedCheckIn, $parsedCheckOut, $sequenceError] = $this->parseCheckInOut($parsedDate, $checkIn, $checkOut);
        if ($sequenceError) {
            return ParsedImportRow::invalid($rowNumber, $row, $sequenceError);
        }

        $score = null;
        if ($performance !== '') {
            if (! ctype_digit($performance) || (int) $performance < PerformanceScore::MIN_SCORE || (int) $performance > PerformanceScore::MAX_SCORE) {
                return ParsedImportRow::invalid($rowNumber, $row, 'invalid score');
            }
            $score = (int) $performance;
        }

        return ParsedImportRow::valid(
            rowNumber: $rowNumber,
            raw: $row,
            employeeEmail: $email,
            date: $parsedDate?->toDateString(),
            checkIn: $parsedCheckIn?->toDateTimeString(),
            checkOut: $parsedCheckOut?->toDateTimeString(),
            score: $score,
        );
    }

    private function parseDate(string $date): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);

            return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?string}
     */
    private function parseCheckInOut(?Carbon $date, string $checkIn, string $checkOut): array
    {
        if ($checkIn === '' && $checkOut === '') {
            return [null, null, null];
        }

        if ($checkIn === '' || $checkOut === '' || $date === null) {
            return [null, null, 'invalid check-in/check-out sequence'];
        }

        $checkInAt = $this->parseTimeOn($date, $checkIn);
        $checkOutAt = $this->parseTimeOn($date, $checkOut);

        // malformed time vs out-of-order are different mistakes, keep the
        // stored reasons distinguishable for whoever reads the error report
        if (! $checkInAt || ! $checkOutAt) {
            return [null, null, 'invalid time'];
        }

        if ($checkOutAt->lessThanOrEqualTo($checkInAt)) {
            return [null, null, 'invalid check-in/check-out sequence'];
        }

        return [$checkInAt, $checkOutAt, null];
    }

    private function parseTimeOn(Carbon $date, string $time): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('H:i', $time);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed || $parsed->format('H:i') !== $time) {
            return null;
        }

        return $date->copy()->setTime($parsed->hour, $parsed->minute);
    }
}
