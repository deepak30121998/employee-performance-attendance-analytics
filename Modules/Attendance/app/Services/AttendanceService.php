<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Attendance\Contracts\AttendanceRepositoryInterface;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Attendance\Exceptions\AttendanceConflictException;
use Modules\Attendance\Models\Attendance;

class AttendanceService
{
    // SQLSTATE 23000 covers every integrity violation (FKs included), so the
    // MySQL errno pins it down to an actual duplicate key
    private const MYSQL_DUPLICATE_KEY_ERRNO = 1062;

    public function __construct(
        private readonly AttendanceRepositoryInterface $attendances,
    ) {}

    public function checkIn(User $employee): Attendance
    {
        $now = Carbon::now();
        $date = $now->toDateString();

        $existing = $this->attendances->findForDate($employee->id, $date);

        if ($existing) {
            throw $existing->isActive()
                ? AttendanceConflictException::alreadyCheckedIn()
                : AttendanceConflictException::alreadyRecordedToday();
        }

        // the check above handles the common case; under a race the unique
        // (employee_id, date) index decides - only one insert wins, the loser's
        // constraint violation gets translated to a 409 below
        try {
            return DB::transaction(fn () => $this->attendances->create([
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'date' => $date,
                'check_in_at' => $now,
                'status' => AttendanceStatus::Present,
                'source' => AttendanceSource::Manual,
            ]));
        } catch (QueryException $e) {
            if (((int) ($e->errorInfo[1] ?? 0)) === self::MYSQL_DUPLICATE_KEY_ERRNO) {
                throw AttendanceConflictException::alreadyCheckedIn();
            }

            throw $e;
        }
    }

    public function checkOut(User $employee): Attendance
    {
        $now = Carbon::now();
        $date = $now->toDateString();

        $existing = $this->attendances->findForDate($employee->id, $date);

        if (! $existing || $existing->check_in_at === null) {
            // no row, or a scheduler-written absent row - nothing to check out of
            throw AttendanceConflictException::noActiveCheckIn();
        }

        if (! $existing->isActive()) {
            throw AttendanceConflictException::alreadyCheckedOut();
        }

        return DB::transaction(function () use ($existing, $now) {
            // re-fetch under a row lock so a concurrent check-out waits here
            // instead of computing working_minutes from a stale read
            $locked = $this->attendances->lockForUpdate($existing->id);

            if (! $locked->isActive()) {
                throw AttendanceConflictException::alreadyCheckedOut();
            }

            $locked->update([
                'check_out_at' => $now,
                'working_minutes' => $locked->check_in_at->diffInMinutes($now),
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listFor(User $actor, array $filters = []): CursorPaginator
    {
        return $this->attendances->paginateForRole($actor, $filters);
    }
}
