<?php

namespace Modules\Attendance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Attendance\Database\Factories\AttendanceFactory;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'department_id',
        'date',
        'check_in_at',
        'check_out_at',
        'working_minutes',
        'status',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function isActive(): bool
    {
        // absent rows written by the scheduler have neither timestamp - those
        // are not "active", there is nothing to check out of
        return $this->check_in_at !== null && $this->check_out_at === null;
    }

    protected static function newFactory(): AttendanceFactory
    {
        return AttendanceFactory::new();
    }
}
