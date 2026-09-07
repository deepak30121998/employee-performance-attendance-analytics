<?php

namespace Modules\Analytics\Tests\Unit;

use Modules\Analytics\Support\WorkingDaysCalculator;
use Modules\Attendance\Contracts\HolidayRepositoryInterface;
use Tests\TestCase;

class WorkingDaysCalculatorTest extends TestCase
{
    private function calculator(array $holidays = []): WorkingDaysCalculator
    {
        $repo = new class($holidays) implements HolidayRepositoryInterface
        {
            public function __construct(private array $holidays) {}

            public function datesBetween(string $from, string $to): array
            {
                return array_values(array_filter($this->holidays, fn ($d) => $d >= $from && $d <= $to));
            }

            public function isHoliday(string $date): bool
            {
                return in_array($date, $this->holidays, true);
            }
        };

        return new WorkingDaysCalculator($repo);
    }

    public function test_counts_weekdays_in_a_full_month(): void
    {
        // August 2026: 31 days, starts Saturday - 21 weekdays.
        $this->assertSame(21, $this->calculator()->countInMonth('2026-08'));
    }

    public function test_counts_weekdays_in_february(): void
    {
        // February 2026: 28 days, starts Sunday - 20 weekdays.
        $this->assertSame(20, $this->calculator()->countInMonth('2026-02'));
    }

    public function test_holidays_reduce_the_working_day_count(): void
    {
        // 2026-08-14 is a Friday; a weekend holiday shouldn't double-count
        $calculator = $this->calculator(['2026-08-14', '2026-08-15']);

        $this->assertSame(20, $calculator->countInMonth('2026-08'));
    }
}
