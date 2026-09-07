<?php

namespace Modules\Performance\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Performance\Models\PerformanceScore;
use Modules\User\Enums\Role;

class PerformanceDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (PerformanceScore::query()->exists()) {
            return;
        }

        $employees = User::query()->where('role', Role::Employee)->get(['id', 'department_id']);

        // one manager per department to attribute the scores to
        $managers = User::query()
            ->where('role', Role::Manager)
            ->whereNotNull('department_id')
            ->get(['id', 'department_id'])
            ->keyBy('department_id');

        $admin = User::query()->where('role', Role::Admin)->first();
        $seededAt = now();

        $months = [
            now()->subMonthsNoOverflow(2)->format('Y-m-01'),
            now()->subMonthNoOverflow()->format('Y-m-01'),
            now()->format('Y-m-01'),
        ];

        $rows = [];

        foreach ($employees as $employee) {
            $scoredBy = $managers[$employee->department_id]->id ?? $admin?->id;

            if ($scoredBy === null) {
                continue;
            }

            foreach ($months as $month) {
                $rows[] = [
                    'employee_id' => $employee->id,
                    'month' => $month,
                    'score' => mt_rand(3, 10),
                    'comment' => null,
                    'created_by' => $scoredBy,
                    'created_at' => $seededAt,
                    'updated_at' => $seededAt,
                ];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('performance_scores')->insert($chunk);
        }
    }
}
