<?php

namespace Modules\Attendance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Attendance\Models\Attendance;
use Modules\Attendance\Models\DailyAttendanceSummary;
use Modules\Attendance\Models\Holiday;
use Modules\Attendance\Notifications\EmployeeAbsentNotification;
use Modules\User\Models\Department;
use Tests\TestCase;

class MarkAbsenteesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_employees_with_no_check_in_as_absent_and_notifies_their_manager(): void
    {
        Notification::fake();

        $department = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $checkedIn = User::factory()->employee()->create(['department_id' => $department->id]);
        $noShow = User::factory()->employee()->create(['department_id' => $department->id]);

        Attendance::factory()->create(['employee_id' => $checkedIn->id, 'date' => '2026-08-03']); // a Monday

        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-03'])->assertSuccessful();

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $noShow->id,
            'date' => '2026-08-03',
            'status' => 'absent',
            'source' => 'system',
        ]);
        $this->assertSame(1, Attendance::where('employee_id', $checkedIn->id)->count());

        Notification::assertSentTo($manager, EmployeeAbsentNotification::class);
    }

    public function test_running_twice_for_the_same_day_does_not_duplicate_or_double_notify(): void
    {
        // Not faked here - the job's own idempotency guard queries the real
        // database_notifications table, so it needs a real row to check against.
        $department = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $noShow = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-03'])->assertSuccessful();
        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-03'])->assertSuccessful();

        $this->assertSame(1, Attendance::where('employee_id', $noShow->id)->where('date', '2026-08-03')->count());
        $this->assertSame(1, $manager->notifications()->where('type', EmployeeAbsentNotification::class)->count());
    }

    public function test_persists_a_daily_summary_and_refreshes_it_on_rerun(): void
    {
        Notification::fake();

        $department = Department::factory()->create();
        User::factory()->manager()->create(['department_id' => $department->id]);
        $checkedIn = User::factory()->employee()->create(['department_id' => $department->id]);
        User::factory()->employee()->create(['department_id' => $department->id]);

        Attendance::factory()->create(['employee_id' => $checkedIn->id, 'date' => '2026-08-03']);

        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-03'])->assertSuccessful();

        $this->assertDatabaseHas('daily_attendance_summaries', [
            'date' => '2026-08-03',
            'total_employees' => 2,
            'present_count' => 1,
            'absent_count' => 1,
            'newly_marked_absent' => 1,
        ]);

        // a re-run refreshes the same row instead of adding a second one
        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-03'])->assertSuccessful();

        $this->assertSame(1, DailyAttendanceSummary::where('date', '2026-08-03')->count());
        $this->assertSame(0, DailyAttendanceSummary::where('date', '2026-08-03')->first()->newly_marked_absent);
    }

    public function test_skips_weekends(): void
    {
        $employee = User::factory()->employee()->create();

        // 2026-08-01 is a Saturday.
        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-01'])->assertSuccessful();

        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id, 'date' => '2026-08-01']);
    }

    public function test_skips_holidays(): void
    {
        $employee = User::factory()->employee()->create();

        // 2026-08-05 is a Wednesday
        Holiday::create(['date' => '2026-08-05', 'name' => 'Test Holiday']);

        $this->artisan('attendance:mark-absentees', ['date' => '2026-08-05'])->assertSuccessful();

        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id, 'date' => '2026-08-05']);
    }
}
