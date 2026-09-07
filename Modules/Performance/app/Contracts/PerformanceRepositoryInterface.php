<?php

namespace Modules\Performance\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Modules\Performance\Models\PerformanceScore;

interface PerformanceRepositoryInterface
{
    public function findForMonth(int $employeeId, string $month): ?PerformanceScore;

    public function create(array $attributes): PerformanceScore;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForRole(User $actor, array $filters = []): CursorPaginator;

    /**
     * Scoping happens in SQL - pass a department for the manager dashboard,
     * an employee for the self dashboard, neither for company-wide.
     */
    public function averageScore(string $month, ?int $departmentId = null, ?int $employeeId = null): ?float;

    /**
     * @return Collection<int, PerformanceScore>
     */
    public function topScorers(string $month, int $limit, ?int $departmentId = null): Collection;

    /**
     * Streamed for the monthly performance CSV export.
     *
     * @return LazyCollection<int, PerformanceScore>
     */
    public function cursorForMonth(string $month): LazyCollection;
}
