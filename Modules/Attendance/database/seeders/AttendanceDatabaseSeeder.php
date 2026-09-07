<?php

namespace Modules\Attendance\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Attendance\Models\Attendance;
use Modules\User\Enums\Role;

class AttendanceDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // don't pile demo rows on top of real data
        if (Attendance::query()->exists()) {
            return;
        }

        $employees = User::query()->where('role', Role::Employee)->get(['id', 'department_id']);
        $start = now()->subMonthNoOverflow()->startOfMonth();
        $end = now()->subDay();
        $seededAt = now();

        $rows = [];

        foreach ($employees as $employee) {
            // each employee gets their own attendance habit, some land below 60%
            $presenceRate = mt_rand(45, 98) / 100;

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                if ($day->isWeekend()) {
                    continue;
                }

                $present = (mt_rand(1, 100) / 100) <= $presenceRate;
                $checkIn = $present ? $day->copy()->setTime(9, mt_rand(0, 55)) : null;
                $checkOut = $present ? $day->copy()->setTime(18, mt_rand(0, 45)) : null;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'department_id' => $employee->department_id,
                    'date' => $day->toDateString(),
                    'check_in_at' => $checkIn,
                    'check_out_at' => $checkOut,
                    'working_minutes' => $present ? $checkIn->diffInMinutes($checkOut) : null,
                    'status' => $present ? AttendanceStatus::Present->value : AttendanceStatus::Absent->value,
                    'source' => AttendanceSource::System->value,
                    'created_at' => $seededAt,
                    'updated_at' => $seededAt,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('attendances')->insert($chunk);
        }
    }
}
