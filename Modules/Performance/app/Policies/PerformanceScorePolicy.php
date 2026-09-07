<?php

namespace Modules\Performance\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PerformanceScorePolicy
{
    use HandlesAuthorization;

    public function create(User $user, User $employee): bool
    {
        // admin can score anyone, a manager only their own department
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isManager()
            && $user->department_id !== null
            && $user->department_id === $employee->department_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
