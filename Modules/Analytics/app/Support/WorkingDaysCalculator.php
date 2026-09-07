<?php

namespace Modules\Analytics\Support;

use Illuminate\Support\Carbon;

/**
 * Counts Mon-Fri working days in a range. A 5-day work week with no holiday
 * calendar is a deliberate v1 simplification - see ARCHITECTURE.md.
 */
class WorkingDaysCalculator
{
    /**
     * @param  string  $month  "Y-m", e.g. "2026-08"
     */
    public function countInMonth(string $month): int
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->countBetween($start, $end);
    }

    public function countBetween(Carbon $from, Carbon $to): int
    {
        $days = 0;

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if (! $date->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
