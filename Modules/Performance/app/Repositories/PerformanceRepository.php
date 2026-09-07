<?php

namespace Modules\Performance\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\Performance\Contracts\PerformanceRepositoryInterface;
use Modules\Performance\Models\PerformanceScore;

class PerformanceRepository implements PerformanceRepositoryInterface
{
    private const PER_PAGE = 50;

    public function findForMonth(int $employeeId, string $month): ?PerformanceScore
    {
        return PerformanceScore::query()
            ->where('employee_id', $employeeId)
            ->where('month', $month)
            ->first();
    }

    public function create(array $attributes): PerformanceScore
    {
        return PerformanceScore::create($attributes);
    }

    public function paginateForRole(User $actor, array $filters = []): CursorPaginator
    {
        $query = PerformanceScore::query()->with('employee')->orderByDesc('month')->orderBy('id');

        if ($actor->isManager()) {
            if ($actor->department_id === null) {
                // dept-less manager manages nobody; the subquery would match dept-less admins
                $query->whereRaw('1 = 0');
            } else {
                // subquery instead of pluck() so the ID list never leaves the DB
                $query->whereIn('employee_id', User::query()
                    ->where('department_id', $actor->department_id)
                    ->select('id'));
            }
        } elseif ($actor->isEmployee()) {
            $query->where('employee_id', $actor->id);
        } elseif (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['month'])) {
            $query->where('month', $filters['month']);
        }

        return $query->cursorPaginate(self::PER_PAGE);
    }

    public function averageScore(string $month, ?int $departmentId = null, ?int $employeeId = null): ?float
    {
        $query = PerformanceScore::query()->where('performance_scores.month', $month);

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        } elseif ($departmentId !== null) {
            // join skips the soft-delete global scope, filter deleted_at by hand
            $query->join('users', 'users.id', '=', 'performance_scores.employee_id')
                ->where('users.department_id', $departmentId)
                ->whereNull('users.deleted_at');
        }

        return $query->avg('performance_scores.score');
    }

    public function topScorers(string $month, int $limit, ?int $departmentId = null): Collection
    {
        $query = PerformanceScore::query()
            ->with('employee')
            ->where('performance_scores.month', $month)
            ->orderByDesc('performance_scores.score');

        if ($departmentId !== null) {
            $query->join('users', 'users.id', '=', 'performance_scores.employee_id')
                ->where('users.department_id', $departmentId)
                ->whereNull('users.deleted_at')
                ->select('performance_scores.*');
        }

        return $query->limit($limit)->get();
    }

    public function cursorForMonth(string $month): LazyCollection
    {
        return PerformanceScore::query()
            ->join('users', 'users.id', '=', 'performance_scores.employee_id')
            ->whereNull('users.deleted_at')
            ->where('performance_scores.month', $month)
            ->orderBy('users.name')
            ->select([
                'performance_scores.employee_id',
                'users.name as employee_name',
                'users.email as employee_email',
                'performance_scores.month',
                'performance_scores.score',
                'performance_scores.comment',
            ])
            ->cursor();
    }
}
