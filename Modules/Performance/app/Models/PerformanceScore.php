<?php

namespace Modules\Performance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Performance\Database\Factories\PerformanceScoreFactory;

class PerformanceScore extends Model
{
    use HasFactory;

    public const MIN_SCORE = 1;

    public const MAX_SCORE = 10;

    protected $fillable = [
        'employee_id',
        'month',
        'score',
        'comment',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'score' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): PerformanceScoreFactory
    {
        return PerformanceScoreFactory::new();
    }
}
