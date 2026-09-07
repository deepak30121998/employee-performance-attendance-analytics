<?php

namespace Modules\Performance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Performance\Models\PerformanceScore;

class PerformanceScoreAdded extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PerformanceScore $score,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'performance_score_id' => $this->score->id,
            'month' => $this->score->month->toDateString(),
            'score' => $this->score->score,
            'comment' => $this->score->comment,
        ];
    }
}
