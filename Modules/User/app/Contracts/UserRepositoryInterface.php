<?php

namespace Modules\User\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

interface UserRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForRole(User $actor, array $filters = []): CursorPaginator;

    public function create(array $attributes): User;

    public function update(User $user, array $attributes): User;

    public function softDelete(User $user): void;

    /**
     * @return Collection<int, User>
     */
    public function managersForDepartment(int $departmentId): Collection;
}
