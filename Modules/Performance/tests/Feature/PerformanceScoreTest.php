<?php

namespace Modules\Performance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Performance\Models\PerformanceScore;
use Modules\Performance\Notifications\PerformanceScoreAdded;
use Modules\User\Models\Department;
use Tests\TestCase;

class PerformanceScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_record_a_score_for_their_own_department(): void
    {
        Notification::fake();

        $department = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $response = $this->actingAs($manager, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'score' => 8,
            'comment' => 'Great sprint.',
        ]);

        $response->assertCreated()->assertJsonPath('data.score', 8);
        $this->assertDatabaseHas('performance_scores', [
            'employee_id' => $employee->id,
            'month' => '2026-08-01',
            'score' => 8,
        ]);
        Notification::assertSentTo($employee, PerformanceScoreAdded::class);
    }

    public function test_manager_cannot_score_an_employee_outside_their_department(): void
    {
        $manager = User::factory()->manager()->create();
        $otherDeptEmployee = User::factory()->employee()->create();

        $this->actingAs($manager, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $otherDeptEmployee->id,
            'month' => '2026-08',
            'score' => 5,
        ])->assertForbidden();
    }

    public function test_employee_cannot_record_a_score(): void
    {
        $employee = User::factory()->employee()->create();
        $coworker = User::factory()->employee()->create(['department_id' => $employee->department_id]);

        $this->actingAs($employee, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $coworker->id,
            'month' => '2026-08',
            'score' => 5,
        ])->assertForbidden();
    }

    public function test_score_out_of_range_is_rejected(): void
    {
        $department = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->actingAs($manager, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'score' => 11,
        ])->assertStatus(422)->assertJsonValidationErrors('score');

        $this->actingAs($manager, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'score' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('score');
    }

    public function test_duplicate_score_for_the_same_month_is_rejected(): void
    {
        $department = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        PerformanceScore::factory()->create([
            'employee_id' => $employee->id,
            'month' => '2026-08-01',
        ]);

        $this->actingAs($manager, 'sanctum')->postJson('/api/performance', [
            'employee_id' => $employee->id,
            'month' => '2026-08',
            'score' => 6,
        ])->assertStatus(409);

        $this->assertSame(1, PerformanceScore::where('employee_id', $employee->id)->where('month', '2026-08-01')->count());
    }

    public function test_employee_only_sees_their_own_performance_history(): void
    {
        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();
        PerformanceScore::factory()->create(['employee_id' => $employee->id]);
        PerformanceScore::factory()->create(['employee_id' => $other->id]);

        $response = $this->actingAs($employee, 'sanctum')->getJson('/api/performance');
        $ids = collect($response->json('data'))->pluck('employee_id')->unique();

        $response->assertOk();
        $this->assertEquals([$employee->id], $ids->all());
    }

    public function test_employee_cannot_read_someone_elses_scores_via_the_employee_id_filter(): void
    {
        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();
        PerformanceScore::factory()->create(['employee_id' => $other->id]);

        // the filter only applies to admins; for an employee it's ignored
        $response = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/performance?employee_id={$other->id}")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
