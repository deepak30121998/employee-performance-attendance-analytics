<?php

namespace Modules\Performance\Observers;

use Modules\Analytics\Support\AnalyticsCache;
use Modules\Performance\Models\PerformanceScore;

class PerformanceScoreObserver
{
    public function __construct(
        private readonly AnalyticsCache $cache,
    ) {}

    public function saved(PerformanceScore $score): void
    {
        $this->cache->flush();
    }

    public function deleted(PerformanceScore $score): void
    {
        $this->cache->flush();
    }
}
