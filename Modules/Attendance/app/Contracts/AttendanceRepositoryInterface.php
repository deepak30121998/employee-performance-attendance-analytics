<?php

namespace Modules\Attendance\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\Attendance\Models\Attendance;

interface AttendanceRepositoryInterface
{
    public function findForDate(int $employeeId, string $date): ?Attendance;

    public function create(array $attributes): Attendance;

    /**
     * Re-fetch and lock the row for update inside the caller's transaction.
     */
    public function lockForUpdate(int $attendanceId): Attendance;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForRole(User $actor, array $filters = []): CursorPaginator;

    /**
     * Total present days in [from, to] for the analytics dashboards.
     *
     * @param  int|null  $departmentId  null = company-wide (employees only)
     * @param  int|null  $employeeId  narrow to one employee (self dashboard)
     */
    public function presentDaysTotal(string $from, string $to, ?int $departmentId = null, ?int $employeeId = null): int;

    /**
     * Employees whose attendance % over [from, to] falls below the threshold.
     * Includes employees with zero attendance rows.
     *
     * @return Collection<int, object{id: int, name: string, email: string, present_days: int}>
     */
    public function employeesBelowAttendanceThreshold(float $thresholdPercent, int $expectedDays, string $from, string $to, ?int $departmentId = null): Collection;

    /**
     * Streamed for the monthly attendance CSV export - never materializes the
     * whole result set in memory.
     *
     * @return LazyCollection<int, Attendance>
     */
    public function cursorForRange(string $from, string $to): LazyCollection;
}
