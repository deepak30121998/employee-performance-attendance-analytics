<?php

namespace Modules\Performance\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\QueryException;
use Modules\Performance\Contracts\PerformanceRepositoryInterface;
use Modules\Performance\Exceptions\PerformanceScoreConflictException;
use Modules\Performance\Jobs\NotifyEmployeeOfPerformanceScore;
use Modules\Performance\Models\PerformanceScore;

class PerformanceService
{
    private const MYSQL_DUPLICATE_KEY_ERRNO = 1062;

    public function __construct(
        private readonly PerformanceRepositoryInterface $scores,
    ) {}

    public function record(User $manager, int $employeeId, string $month, int $score, ?string $comment): PerformanceScore
    {
        if ($this->scores->findForMonth($employeeId, $month)) {
            throw PerformanceScoreConflictException::alreadyRecordedForMonth();
        }

        // two concurrent requests can both pass the check above - the unique
        // (employee_id, month) index decides the loser, which gets a 409 here
        try {
            $record = $this->scores->create([
                'employee_id' => $employeeId,
                'month' => $month,
                'score' => $score,
                'comment' => $comment,
                'created_by' => $manager->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                throw PerformanceScoreConflictException::alreadyRecordedForMonth();
            }

            throw $e;
        }

        NotifyEmployeeOfPerformanceScore::dispatch($record);

        return $record;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ((int) ($e->errorInfo[1] ?? 0)) === self::MYSQL_DUPLICATE_KEY_ERRNO;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listFor(User $actor, array $filters = []): CursorPaginator
    {
        return $this->scores->paginateForRole($actor, $filters);
    }
}
