<?php

namespace Modules\Analytics\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Analytics\Support\AnalyticsCache;
use Modules\Analytics\Support\WorkingDaysCalculator;
use Modules\Attendance\Contracts\AttendanceRepositoryInterface;
use Modules\Performance\Contracts\PerformanceRepositoryInterface;
use Modules\Performance\Models\PerformanceScore;
use Modules\User\Enums\Role;

class AnalyticsService
{
    // dashboards are read-heavy; ~30 min is fine, writes bump the cache version anyway
    private const TTL_SECONDS = 1800;

    private const COMPANY_LOW_ATTENDANCE_THRESHOLD = 60.0;

    private const DEPARTMENT_IRREGULAR_ATTENDANCE_THRESHOLD = 75.0;

    public function __construct(
        private readonly AttendanceRepositoryInterface $attendance,
        private readonly PerformanceRepositoryInterface $performance,
        private readonly WorkingDaysCalculator $workingDays,
        private readonly AnalyticsCache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $actor, string $month): array
    {
        return match (true) {
            $actor->isAdmin() => $this->adminDashboard($month),
            $actor->isManager() => $this->managerDashboard($actor, $month),
            default => $this->employeeDashboard($actor, $month),
        };
    }

    private function adminDashboard(string $month): array
    {
        return $this->cache->remember(
            $this->cacheKey('admin', 'company', $month),
            self::TTL_SECONDS,
            function () use ($month) {
                [$from, $to] = $this->monthRange($month);
                $expectedDays = $this->workingDays->countInMonth($month);
                $totalEmployees = User::query()->where('role', Role::Employee)->count();

                $lowAttendance = $this->attendance->employeesBelowAttendanceThreshold(
                    self::COMPANY_LOW_ATTENDANCE_THRESHOLD, $expectedDays, $from, $to
                );

                return [
                    'scope' => 'company',
                    'month' => $month,
                    'total_employees' => $totalEmployees,
                    'attendance_percentage' => $this->aggregateAttendancePercentage(
                        $totalEmployees,
                        $this->attendance->presentDaysTotal($from, $to),
                        $expectedDays
                    ),
                    'average_performance_score' => $this->roundedAverage($this->performance->averageScore($this->storedMonth($month))),
                    'top_performers' => $this->formatScorers($this->performance->topScorers($this->storedMonth($month), 5)),
                    'employees_below_60_percent_attendance' => $this->formatEmployees($lowAttendance),
                ];
            }
        );
    }

    private function managerDashboard(User $manager, string $month): array
    {
        return $this->cache->remember(
            $this->cacheKey('manager', (string) $manager->department_id, $month),
            self::TTL_SECONDS,
            function () use ($manager, $month) {
                [$from, $to] = $this->monthRange($month);
                $expectedDays = $this->workingDays->countInMonth($month);

                $employeeCount = User::query()
                    ->where('department_id', $manager->department_id)
                    ->where('role', Role::Employee)
                    ->count();

                $irregular = $this->attendance->employeesBelowAttendanceThreshold(
                    self::DEPARTMENT_IRREGULAR_ATTENDANCE_THRESHOLD, $expectedDays, $from, $to, $manager->department_id
                );

                return [
                    'scope' => 'department',
                    'department_id' => $manager->department_id,
                    'month' => $month,
                    'attendance_percentage' => $this->aggregateAttendancePercentage(
                        $employeeCount,
                        $this->attendance->presentDaysTotal($from, $to, $manager->department_id),
                        $expectedDays
                    ),
                    'average_performance_score' => $this->roundedAverage($this->performance->averageScore($this->storedMonth($month), $manager->department_id)),
                    'employees_with_irregular_attendance' => $this->formatEmployees($irregular),
                ];
            }
        );
    }

    private function employeeDashboard(User $employee, string $month): array
    {
        return $this->cache->remember(
            $this->cacheKey('employee', (string) $employee->id, $month),
            self::TTL_SECONDS,
            function () use ($employee, $month) {
                [$from, $to] = $this->monthRange($month);
                $expectedDays = $this->workingDays->countInMonth($month);

                return [
                    'scope' => 'self',
                    'month' => $month,
                    'attendance_percentage' => $this->aggregateAttendancePercentage(
                        1,
                        $this->attendance->presentDaysTotal($from, $to, null, $employee->id),
                        $expectedDays
                    ),
                    'average_performance_score' => $this->roundedAverage($this->performance->averageScore($this->storedMonth($month), null, $employee->id)),
                ];
            }
        );
    }

    private function aggregateAttendancePercentage(int $employeeCount, int $totalPresentDays, int $expectedDays): float
    {
        if ($employeeCount === 0 || $expectedDays === 0) {
            return 0.0;
        }

        return round(($totalPresentDays / ($employeeCount * $expectedDays)) * 100, 2);
    }

    private function roundedAverage(?float $average): ?float
    {
        return $average !== null ? round($average, 2) : null;
    }

    // performance_scores.month is stored as first-of-month ("Y-m-d"); the API
    // and WorkingDaysCalculator both work in "Y-m".
    private function storedMonth(string $month): string
    {
        return $month.'-01';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * @param  Collection<int, PerformanceScore>  $scorers
     * @return array<int, array<string, mixed>>
     */
    private function formatScorers($scorers): array
    {
        return $scorers->map(fn ($score) => [
            'employee_id' => $score->employee_id,
            'name' => $score->employee->name,
            'score' => $score->score,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, object{id: int, name: string, email: string, present_days: int}>  $employees
     * @return array<int, array<string, mixed>>
     */
    private function formatEmployees(Collection $employees): array
    {
        return $employees
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values()
            ->all();
    }

    private function cacheKey(string $scope, string $scopeId, string $month): string
    {
        return "{$scope}:{$scopeId}:{$month}";
    }
}
