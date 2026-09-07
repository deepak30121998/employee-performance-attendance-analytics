<?php

namespace Modules\Attendance\Models;

use Illuminate\Database\Eloquent\Model;

class DailyAttendanceSummary extends Model
{
    protected $fillable = [
        'date',
        'total_employees',
        'present_count',
        'absent_count',
        'newly_marked_absent',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'generated_at' => 'datetime',
        ];
    }
}
