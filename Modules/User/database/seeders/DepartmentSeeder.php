<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\Department;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Engineering', 'Sales', 'Human Resources', 'Finance'] as $name) {
            Department::firstOrCreate(['name' => $name]);
        }
    }
}
