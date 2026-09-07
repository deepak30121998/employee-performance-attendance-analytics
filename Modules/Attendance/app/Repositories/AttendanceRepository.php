<?php

namespace Modules\Attendance\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Modules\Attendance\Contracts\AttendanceRepositoryInterface;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Attendance\Models\Attendance;
use Modules\User\Enums\Role;

class AttendanceRepository implements AttendanceRepositoryInterface
{
    private const PER_PAGE = 50;

    public function findForDate(int $employeeId, string $date): ?Attendance
    {
        return Attendance::query()
            ->where('employee_id', $employeeId)
            ->where('date', $date)
            ->first();
    }

    public function create(array $attributes): Attendance
    {
        return Attendance::create($attributes);
    }

    public function lockForUpdate(int $attendanceId): Attendance
    {
        return Attendance::query()->lockForUpdate()->findOrFail($attendanceId);
    }

    public function paginateForRole(User $actor, array $filters = []): CursorPaginator
    {
        $query = Attendance::query()->with('employee')->orderByDesc('date')->orderBy('id');

        if ($actor->isManager()) {
            if ($actor->department_id === null) {
                // where('department_id', null) compiles to IS NULL and would leak dept-less rows
                $query->whereRaw('1 = 0');
            } else {
                // department_id is denormalized here so this hits the (department_id, date) index
                $query->where('department_id', $actor->department_id);
            }
        } elseif ($actor->isEmployee()) {
            $query->where('employee_id', $actor->id);
        } elseif (! empty($filters['employee_id'])) {
            // Admin may narrow to a single employee.
            $query->where('employee_id', $filters['employee_id']);
        }

        // plain where(), not whereDate() - DATE(col) defeats the index
        if (! empty($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->cursorPaginate(self::PER_PAGE);
    }

    public function presentDaysTotal(string $from, string $to, ?int $departmentId = null, ?int $employeeId = null): int
    {
        $query = Attendance::query()
            ->where('attendances.status', AttendanceStatus::Present)
            ->whereBetween('attendances.date', [$from, $to]);

        if ($employeeId !== null) {
            return $query->where('attendances.employee_id', $employeeId)->count();
        }

        // scope via join, not a plucked whereIn list; joins skip the
        // soft-delete global scope so filter deleted_at by hand
        $query->join('users', 'users.id', '=', 'attendances.employee_id')
            ->where('users.role', Role::Employee->value)
            ->whereNull('users.deleted_at');

        if ($departmentId !== null) {
            $query->where('users.department_id', $departmentId);
        }

        return $query->count();
    }

    public function employeesBelowAttendanceThreshold(float $thresholdPercent, int $expectedDays, string $from, string $to, ?int $departmentId = null): Collection
    {
        if ($expectedDays === 0) {
            return collect();
        }

        // LEFT JOIN so employees with zero rows still show up (COUNT = 0).
        // present/expected*100 < t rearranged to present*100 < t*expected
        return User::query()
            ->leftJoin('attendances', function (JoinClause $join) use ($from, $to) {
                $join->on('attendances.employee_id', '=', 'users.id')
                    ->where('attendances.status', AttendanceStatus::Present->value)
                    ->whereBetween('attendances.date', [$from, $to]);
            })
            ->where('users.role', Role::Employee->value)
            ->when($departmentId !== null, fn ($q) => $q->where('users.department_id', $departmentId))
            ->groupBy('users.id', 'users.name', 'users.email')
            ->havingRaw('COUNT(attendances.id) * 100 < ? * ?', [$thresholdPercent, $expectedDays])
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                DB::raw('COUNT(attendances.id) as present_days'),
            ]);
    }

    public function cursorForRange(string $from, string $to): LazyCollection
    {
        // cursor() does not support with() eager loading, so the employee's
        // name/email is pulled via a join instead of risking an N+1 per row.
        return Attendance::query()
            ->join('users', 'users.id', '=', 'attendances.employee_id')
            ->whereNull('users.deleted_at')
            ->whereBetween('attendances.date', [$from, $to])
            ->orderBy('attendances.date')
            ->orderBy('attendances.employee_id')
            ->select([
                'attendances.employee_id',
                'users.name as employee_name',
                'users.email as employee_email',
                'attendances.date',
                'attendances.check_in_at',
                'attendances.check_out_at',
                'attendances.working_minutes',
                'attendances.status',
            ])
            ->cursor();
    }
}
