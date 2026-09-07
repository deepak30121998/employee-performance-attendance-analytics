<?php

namespace Modules\Performance\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PerformanceScorePolicy
{
    use HandlesAuthorization;

    public function create(User $manager, User $employee): bool
    {
        return $manager->isManager()
            && $manager->department_id !== null
            && $manager->department_id === $employee->department_id;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }
}
