<?php

namespace Modules\Attendance\Observers;

use Modules\Analytics\Support\AnalyticsCache;
use Modules\Attendance\Models\Attendance;

class AttendanceObserver
{
    public function __construct(
        private readonly AnalyticsCache $cache,
    ) {}

    // bumping the whole analytics cache is coarse but simple and correct -
    // dashboards just recompute on the next read
    public function saved(Attendance $attendance): void
    {
        $this->cache->flush();
    }

    public function deleted(Attendance $attendance): void
    {
        $this->cache->flush();
    }
}
