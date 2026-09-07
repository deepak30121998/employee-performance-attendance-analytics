<?php

namespace Modules\Attendance\Repositories;

use Modules\Attendance\Contracts\HolidayRepositoryInterface;
use Modules\Attendance\Models\Holiday;

class HolidayRepository implements HolidayRepositoryInterface
{
    public function datesBetween(string $from, string $to): array
    {
        return Holiday::query()
            ->whereBetween('date', [$from, $to])
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->all();
    }

    public function isHoliday(string $date): bool
    {
        return Holiday::query()->where('date', $date)->exists();
    }
}
