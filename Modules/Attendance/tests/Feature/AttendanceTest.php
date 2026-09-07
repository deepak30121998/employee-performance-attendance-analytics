<?php

namespace Modules\Attendance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Attendance\Models\Attendance;
use Modules\User\Models\Department;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_check_in(): void
    {
        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in');

        $response->assertCreated();
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'check_out_at' => null]);
    }

    public function test_check_in_twice_in_a_row_is_rejected(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in')->assertCreated();
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in')->assertStatus(409);

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_check_out_without_check_in_is_rejected(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/attendance/check-out')
            ->assertStatus(409);
    }

    public function test_check_out_calculates_working_hours(): void
    {
        $employee = User::factory()->employee()->create();

        Carbon::setTestNow(Carbon::parse('2026-09-07 09:00:00'));
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in')->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-09-07 17:30:00'));
        $response = $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-out');

        $response->assertOk()->assertJsonPath('data.working_minutes', 510);
        Carbon::setTestNow();
    }

    public function test_check_out_twice_is_rejected(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in');
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-out')->assertOk();
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-out')->assertStatus(409);
    }

    public function test_check_out_against_a_scheduler_absent_row_is_rejected(): void
    {
        $employee = User::factory()->employee()->create();

        // scheduler-written absent rows have no check_in_at at all
        Attendance::factory()->absent()->create([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/attendance/check-out')
            ->assertStatus(409);
    }

    public function test_check_in_after_checking_out_the_same_day_is_rejected(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in')->assertCreated();
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-out')->assertOk();
        $this->actingAs($employee, 'sanctum')->postJson('/api/attendance/check-in')->assertStatus(409);

        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    public function test_employee_cannot_read_someone_elses_rows_via_the_employee_id_filter(): void
    {
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $coworker = User::factory()->employee()->create(['department_id' => $department->id]);

        Attendance::factory()->create(['employee_id' => $coworker->id, 'date' => '2026-08-03']);

        // the filter only applies to admins; for an employee it's ignored
        $response = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/attendance?employee_id={$coworker->id}")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_employee_only_sees_their_own_attendance(): void
    {
        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();
        Attendance::factory()->create(['employee_id' => $employee->id]);
        Attendance::factory()->create(['employee_id' => $other->id]);

        $response = $this->actingAs($employee, 'sanctum')->getJson('/api/attendance');

        $ids = collect($response->json('data'))->pluck('employee_id')->unique();

        $response->assertOk();
        $this->assertEquals([$employee->id], $ids->all());
    }

    public function test_manager_only_sees_their_departments_attendance(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();

        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $ownTeamMember = User::factory()->employee()->create(['department_id' => $department->id]);
        $otherDeptEmployee = User::factory()->employee()->create(['department_id' => $otherDepartment->id]);

        Attendance::factory()->create(['employee_id' => $ownTeamMember->id]);
        Attendance::factory()->create(['employee_id' => $otherDeptEmployee->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/attendance');
        $ids = collect($response->json('data'))->pluck('employee_id')->all();

        $this->assertContains($ownTeamMember->id, $ids);
        $this->assertNotContains($otherDeptEmployee->id, $ids);
    }
}
