<?php

namespace Modules\Attendance\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Attendance\Models\Attendance;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function configure(): static
    {
        // department_id is denormalized from the employee - backfill it from
        // whichever employee_id the factory (or an override) resolved to.
        return $this->afterMaking(function (Attendance $attendance) {
            if ($attendance->department_id === null && $attendance->employee_id) {
                $attendance->department_id = User::find($attendance->employee_id)?->department_id;
            }
        });
    }

    public function definition(): array
    {
        $date = fake()->unique()->dateTimeBetween('-2 months', 'now');
        $checkIn = (clone $date)->setTime(fake()->numberBetween(9, 10), fake()->numberBetween(0, 59));
        $checkOut = (clone $checkIn)->modify('+'.fake()->numberBetween(7, 10).' hours');

        return [
            'employee_id' => User::factory(),
            'date' => $date->format('Y-m-d'),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'working_minutes' => $checkIn->diff($checkOut)->h * 60 + $checkIn->diff($checkOut)->i,
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::Manual,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'check_out_at' => null,
            'working_minutes' => null,
        ]);
    }

    public function absent(): static
    {
        return $this->state(fn (array $attributes) => [
            'check_in_at' => null,
            'check_out_at' => null,
            'working_minutes' => null,
            'status' => AttendanceStatus::Absent,
        ]);
    }
}
