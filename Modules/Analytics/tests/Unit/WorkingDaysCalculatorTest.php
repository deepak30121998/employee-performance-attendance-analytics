<?php

namespace Modules\Analytics\Tests\Unit;

use Modules\Analytics\Support\WorkingDaysCalculator;
use Tests\TestCase;

class WorkingDaysCalculatorTest extends TestCase
{
    public function test_counts_weekdays_in_a_full_month(): void
    {
        // August 2026: 31 days, starts Saturday - 21 weekdays.
        $this->assertSame(21, (new WorkingDaysCalculator)->countInMonth('2026-08'));
    }

    public function test_counts_weekdays_in_february(): void
    {
        // February 2026: 28 days, starts Sunday - 20 weekdays.
        $this->assertSame(20, (new WorkingDaysCalculator)->countInMonth('2026-02'));
    }
}
