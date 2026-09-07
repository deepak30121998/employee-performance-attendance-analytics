<?php

namespace Modules\User\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Modules\User\Contracts\UserRepositoryInterface;
use Modules\User\Enums\Role;

class UserRepository implements UserRepositoryInterface
{
    private const PER_PAGE = 25;

    public function paginateForRole(User $actor, array $filters = []): CursorPaginator
    {
        $query = User::query()->with('department');

        if ($actor->isManager()) {
            if ($actor->department_id === null) {
                // dept-less manager: where('department_id', null) would match admins
                $query->whereRaw('1 = 0');
            } else {
                $query->where('department_id', $actor->department_id);
            }
        } elseif ($actor->isEmployee()) {
            $query->whereKey($actor->id);
        }
        // Admins see everyone; no extra scope.

        if (! empty($filters['department_id']) && $actor->isAdmin()) {
            $query->where('department_id', $filters['department_id']);
        }

        return $query->orderBy('id')->cursorPaginate(self::PER_PAGE);
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $user->update($attributes);

        return $user;
    }

    public function softDelete(User $user): void
    {
        $user->delete();
    }

    public function managersForDepartment(int $departmentId): Collection
    {
        return User::query()
            ->where('department_id', $departmentId)
            ->where('role', Role::Manager)
            ->get();
    }
}
