<?php

namespace Modules\Analytics\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Analytics\Support\AnalyticsCache;
use Modules\Attendance\Models\Attendance;
use Modules\Performance\Models\PerformanceScore;
use Modules\User\Models\Department;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_company_wide_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        PerformanceScore::factory()->create(['employee_id' => $employee->id, 'month' => '2026-08-01', 'score' => 9]);
        Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-08-03']); // a Monday

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/analytics?month=2026-08');

        $response->assertOk()
            ->assertJsonPath('data.scope', 'company')
            ->assertJsonPath('data.total_employees', 1)
            ->assertJsonPath('data.average_performance_score', 9);
    }

    public function test_manager_sees_only_their_department(): void
    {
        $departmentA = Department::factory()->create();
        $departmentB = Department::factory()->create();

        $manager = User::factory()->manager()->create(['department_id' => $departmentA->id]);
        $ownEmployee = User::factory()->employee()->create(['department_id' => $departmentA->id]);
        $otherEmployee = User::factory()->employee()->create(['department_id' => $departmentB->id]);

        PerformanceScore::factory()->create(['employee_id' => $ownEmployee->id, 'month' => '2026-08-01', 'score' => 6]);
        PerformanceScore::factory()->create(['employee_id' => $otherEmployee->id, 'month' => '2026-08-01', 'score' => 10]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/analytics?month=2026-08');

        $response->assertOk()
            ->assertJsonPath('data.scope', 'department')
            ->assertJsonPath('data.average_performance_score', 6);
    }

    public function test_employee_sees_only_their_own_summary(): void
    {
        $employee = User::factory()->employee()->create();
        PerformanceScore::factory()->create(['employee_id' => $employee->id, 'month' => '2026-08-01', 'score' => 4]);

        $response = $this->actingAs($employee, 'sanctum')->getJson('/api/analytics?month=2026-08');

        $response->assertOk()
            ->assertJsonPath('data.scope', 'self')
            ->assertJsonPath('data.average_performance_score', 4);
    }

    public function test_dashboard_is_cached_until_explicitly_invalidated(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();
        PerformanceScore::factory()->create(['employee_id' => $employee->id, 'month' => '2026-08-01', 'score' => 5]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/analytics?month=2026-08')
            ->assertJsonPath('data.total_employees', 1);

        // Written via the query builder, like the import job's bulk inserts -
        // bypasses the PerformanceScore observer, so the cache does not know.
        DB::table('users')->insert([
            'name' => 'Second Employee',
            'email' => 'second.employee@example.com',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/analytics?month=2026-08')
            ->assertJsonPath('data.total_employees', 1); // still cached

        app(AnalyticsCache::class)->flush();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/analytics?month=2026-08')
            ->assertJsonPath('data.total_employees', 2);
    }
}
