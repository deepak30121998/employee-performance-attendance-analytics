<?php

namespace Modules\Import\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Import\Enums\ImportStatus;

class ImportBatch extends Model
{
    protected $fillable = [
        'uploaded_by',
        'original_filename',
        'disk_path',
        'checksum',
        'status',
        'total_rows',
        'processed_rows',
        'last_processed_row',
        'imported_attendance_count',
        'imported_performance_count',
        'skipped_row_count',
        'failed_row_count',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rowErrors(): HasMany
    {
        return $this->hasMany(ImportRowError::class);
    }
}
