<?php

namespace Modules\Attendance\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Attendance\Models\Attendance;

class AttendancePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attendance $attendance): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isManager()) {
            return $user->department_id !== null && $user->department_id === $attendance->employee->department_id;
        }

        return $user->id === $attendance->employee_id;
    }
}
