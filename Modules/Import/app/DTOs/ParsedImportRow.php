<?php

namespace Modules\Import\DTOs;

final readonly class ParsedImportRow
{
    /**
     * @param  array<string, string|null>  $raw
     */
    private function __construct(
        public int $rowNumber,
        public array $raw,
        public ?string $employeeEmail = null,
        public ?string $date = null,
        public ?string $checkIn = null,
        public ?string $checkOut = null,
        public ?int $score = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, string|null>  $raw
     */
    public static function valid(
        int $rowNumber,
        array $raw,
        string $employeeEmail,
        ?string $date,
        ?string $checkIn,
        ?string $checkOut,
        ?int $score,
    ): self {
        return new self(
            rowNumber: $rowNumber,
            raw: $raw,
            employeeEmail: $employeeEmail,
            date: $date,
            checkIn: $checkIn,
            checkOut: $checkOut,
            score: $score,
        );
    }

    /**
     * @param  array<string, string|null>  $raw
     */
    public static function invalid(int $rowNumber, array $raw, string $reason): self
    {
        return new self(rowNumber: $rowNumber, raw: $raw, error: $reason);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    public function hasAttendance(): bool
    {
        return $this->date !== null && $this->checkIn !== null && $this->checkOut !== null;
    }

    public function hasPerformance(): bool
    {
        return $this->score !== null;
    }
}
