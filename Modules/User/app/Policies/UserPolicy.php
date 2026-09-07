<?php

namespace Modules\User\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    public function view(User $user, User $employee): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isManager()) {
            return $user->department_id !== null && $user->department_id === $employee->department_id;
        }

        return $user->is($employee);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $employee): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isManager() && $user->department_id !== null && $user->department_id === $employee->department_id;
    }

    public function delete(User $user, User $employee): bool
    {
        return $user->isAdmin();
    }
}
