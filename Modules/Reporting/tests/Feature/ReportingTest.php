<?php

namespace Modules\Reporting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Attendance\Models\Attendance;
use Modules\Performance\Models\PerformanceScore;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_attendance_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['email' => 'rahul@test.com']);
        Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-08-05']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/reports/attendance?from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('rahul@test.com', $response->streamedContent());
    }

    public function test_admin_can_export_performance_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['email' => 'amit@test.com']);
        PerformanceScore::factory()->create(['employee_id' => $employee->id, 'month' => '2026-08-01', 'score' => 7]);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/reports/performance?month=2026-08');

        $response->assertOk();
        $this->assertStringContainsString('amit@test.com', $response->streamedContent());
        $this->assertStringContainsString('7', $response->streamedContent());
    }

    public function test_manager_cannot_export_reports(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'sanctum')
            ->get('/api/reports/attendance?from=2026-08-01&to=2026-08-31')
            ->assertForbidden();
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/reports/attendance?from=2026-08-31&to=2026-08-01')
            ->assertStatus(422);
    }
}
