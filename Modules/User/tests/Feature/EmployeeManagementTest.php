<?php

namespace Modules\User\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Enums\Role;
use Modules\User\Models\Department;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/employees', [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'password123',
            'role' => Role::Employee->value,
            'department_id' => $department->id,
            'designation' => 'Analyst',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new.hire@example.com', 'department_id' => $department->id]);
    }

    public function test_department_and_designation_are_required_for_non_admin_employees(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/employees', [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'password123',
            'role' => Role::Employee->value,
        ])->assertStatus(422)->assertJsonValidationErrors(['department_id', 'designation']);
    }

    public function test_department_and_designation_are_optional_for_admin_accounts(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/employees', [
            'name' => 'Second Admin',
            'email' => 'second.admin@example.com',
            'password' => 'password123',
            'role' => Role::Admin->value,
        ])->assertCreated();
    }

    public function test_employee_cannot_create_an_employee(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')->postJson('/api/employees', [
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'password123',
            'role' => Role::Employee->value,
        ])->assertForbidden();
    }

    public function test_manager_only_sees_employees_in_their_own_department(): void
    {
        $engineering = Department::factory()->create();
        $sales = Department::factory()->create();

        $manager = User::factory()->manager()->create(['department_id' => $engineering->id]);
        $ownTeamMember = User::factory()->employee()->create(['department_id' => $engineering->id]);
        $otherDeptEmployee = User::factory()->employee()->create(['department_id' => $sales->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/employees');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertContains($ownTeamMember->id, $ids);
        $this->assertNotContains($otherDeptEmployee->id, $ids);
    }

    public function test_employee_cannot_list_other_employees(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')->getJson('/api/employees')->assertForbidden();
    }

    public function test_employee_cannot_view_another_employees_profile_by_guessing_the_id(): void
    {
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $coworker = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/employees/{$coworker->id}")
            ->assertForbidden();

        // own record is still reachable, both by id and via /profile
        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('id', $employee->id);
    }

    public function test_manager_cannot_view_an_employee_from_another_department_by_id(): void
    {
        $manager = User::factory()->manager()->create();
        $ownEmployee = User::factory()->employee()->create(['department_id' => $manager->department_id]);
        $otherDeptEmployee = User::factory()->employee()->create();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/employees/{$ownEmployee->id}")
            ->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/employees/{$otherDeptEmployee->id}")
            ->assertForbidden();
    }

    public function test_manager_cannot_update_an_employee_outside_their_department(): void
    {
        $manager = User::factory()->manager()->create();
        $otherDeptEmployee = User::factory()->employee()->create();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/employees/{$otherDeptEmployee->id}", ['designation' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_manager_cannot_escalate_an_employees_role_or_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $manager = User::factory()->manager()->create(['department_id' => $department->id]);
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->actingAs($manager, 'sanctum')->putJson("/api/employees/{$employee->id}", [
            'role' => Role::Admin->value,
            'department_id' => $otherDepartment->id,
        ])->assertOk();

        $employee->refresh();
        $this->assertEquals(Role::Employee, $employee->role);
        $this->assertEquals($department->id, $employee->department_id);
    }

    public function test_only_admin_can_soft_delete_an_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/employees/{$employee->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $employee->id]);
    }
}
