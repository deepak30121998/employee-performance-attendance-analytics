<?php

namespace Modules\Attendance\Contracts;

interface HolidayRepositoryInterface
{
    /**
     * Holiday dates within [from, to], as "Y-m-d" strings.
     *
     * @return array<int, string>
     */
    public function datesBetween(string $from, string $to): array;

    public function isHoliday(string $date): bool;
}
