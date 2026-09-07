<?php

namespace Modules\User\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\User\Enums\Role;
use Modules\User\Models\Department;

class UserDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DepartmentSeeder::class);

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => 'password', 'role' => Role::Admin]
        );

        $engineering = Department::where('name', 'Engineering')->first();

        User::firstOrCreate(
            ['email' => 'manager@example.com'],
            ['name' => 'Engineering Manager', 'password' => 'password', 'role' => Role::Manager, 'department_id' => $engineering->id]
        );

        User::firstOrCreate(
            ['email' => 'employee@example.com'],
            ['name' => 'Rahul Employee', 'password' => 'password', 'role' => Role::Employee, 'department_id' => $engineering->id, 'designation' => 'Software Engineer']
        );

        // demo staff so the dashboards/reports have something to show
        $designations = [
            'Engineering' => 'Software Engineer',
            'Sales' => 'Sales Executive',
            'Human Resources' => 'HR Executive',
            'Finance' => 'Accountant',
        ];

        foreach (Department::all() as $department) {
            $slug = str($department->name)->slug();

            User::firstOrCreate(
                ['email' => "manager.{$slug}@example.com"],
                [
                    'name' => "{$department->name} Manager",
                    'password' => 'password',
                    'role' => Role::Manager,
                    'department_id' => $department->id,
                ]
            );

            for ($i = 1; $i <= 5; $i++) {
                User::firstOrCreate(
                    ['email' => "{$slug}.employee{$i}@example.com"],
                    [
                        'name' => "{$department->name} Employee {$i}",
                        'password' => 'password',
                        'role' => Role::Employee,
                        'department_id' => $department->id,
                        'designation' => $designations[$department->name] ?? 'Executive',
                    ]
                );
            }
        }
    }
}
