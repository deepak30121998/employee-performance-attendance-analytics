<?php

namespace Modules\Attendance\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Analytics\Support\AnalyticsCache;
use Modules\Attendance\Contracts\HolidayRepositoryInterface;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Attendance\Jobs\NotifyManagerOfAbsence;
use Modules\Attendance\Models\DailyAttendanceSummary;
use Modules\User\Enums\Role;

class MarkAbsenteesCommand extends Command
{
    protected $signature = 'attendance:mark-absentees {date? : Y-m-d, defaults to today}';

    protected $description = 'Mark employees absent for a day with no check-in, notify their managers, and record the daily attendance summary.';

    public function handle(HolidayRepositoryInterface $holidays): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $carbonDate = Carbon::parse($date);

        if ($carbonDate->isWeekend()) {
            $this->info("{$date} is a weekend - nothing to mark.");

            return self::SUCCESS;
        }

        if ($holidays->isHoliday($date)) {
            $this->info("{$date} is a holiday - nothing to mark.");

            return self::SUCCESS;
        }

        // anyone already recorded for the day is excluded up front, which is
        // what makes a same-day re-run a no-op
        $alreadyRecorded = DB::table('attendances')->where('date', $date)->pluck('employee_id');

        $toMark = User::query()
            ->where('role', Role::Employee)
            ->whereNotIn('id', $alreadyRecorded)
            ->get(['id', 'department_id']);

        $now = now();
        $rows = $toMark->map(fn ($employee) => [
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'date' => $date,
            'status' => AttendanceStatus::Absent->value,
            'source' => AttendanceSource::System->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // insertOrIgnore: a manual run racing the scheduled one just skips the
        // rows the other already wrote instead of aborting on the unique index
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('attendances')->insertOrIgnore($chunk);
        }

        foreach ($toMark as $employee) {
            NotifyManagerOfAbsence::dispatch($employee->id, $date);
        }

        $presentCount = DB::table('attendances')->where('date', $date)->where('status', AttendanceStatus::Present->value)->count();
        $absentCount = DB::table('attendances')->where('date', $date)->where('status', AttendanceStatus::Absent->value)->count();

        // persisted summary, one row per day - a re-run just refreshes the counts
        DailyAttendanceSummary::updateOrCreate(
            ['date' => $date],
            [
                'total_employees' => User::query()->where('role', Role::Employee)->count(),
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'newly_marked_absent' => count($rows),
                'generated_at' => now(),
            ]
        );

        Log::info('Daily attendance summary', [
            'date' => $date,
            'present' => $presentCount,
            'absent' => $absentCount,
            'newly_marked_absent' => count($rows),
        ]);

        // bulk insert bypasses the observer, so bump the cache version here
        app(AnalyticsCache::class)->flush();

        $this->info("Marked {$this->pluralizeCount(count($rows))} absent for {$date}. Present: {$presentCount}, Absent: {$absentCount}.");

        return self::SUCCESS;
    }

    private function pluralizeCount(int $count): string
    {
        return $count === 1 ? '1 employee' : "{$count} employees";
    }
}
