<?php

namespace Modules\Performance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Performance\Models\PerformanceScore;
use Modules\Performance\Notifications\PerformanceScoreAdded;

class NotifyEmployeeOfPerformanceScore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly PerformanceScore $score,
    ) {}

    // ShouldBeUnique guards concurrent workers; the query below guards a
    // retry after a crash mid-delivery, which would otherwise write a
    // second notification row for the same score
    public function uniqueId(): string
    {
        return "performance-score:{$this->score->id}";
    }

    public function handle(): void
    {
        $alreadyNotified = DatabaseNotification::query()
            ->where('type', PerformanceScoreAdded::class)
            ->where('data->performance_score_id', $this->score->id)
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $this->score->employee->notify(new PerformanceScoreAdded($this->score));
    }
}
