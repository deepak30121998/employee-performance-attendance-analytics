<?php

namespace Modules\Analytics\Support;

use Illuminate\Support\Carbon;
use Modules\Attendance\Contracts\HolidayRepositoryInterface;

/**
 * Counts working days in a range: Mon-Fri minus the holiday calendar.
 */
class WorkingDaysCalculator
{
    public function __construct(
        private readonly HolidayRepositoryInterface $holidays,
    ) {}

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
        $holidays = $this->holidays->datesBetween($from->toDateString(), $to->toDateString());
        $days = 0;

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if (! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return $days;
    }
}
