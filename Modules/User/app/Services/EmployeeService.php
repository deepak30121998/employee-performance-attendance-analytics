<?php

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Modules\User\Contracts\UserRepositoryInterface;
use Modules\User\DTOs\EmployeeData;

class EmployeeService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function listFor(User $actor, array $filters = []): CursorPaginator
    {
        return $this->users->paginateForRole($actor, $filters);
    }

    public function create(EmployeeData $data): User
    {
        return $this->users->create($data->toModelAttributes());
    }

    /**
     * @param  array<string, mixed>  $attributes  already validated, partial-update shaped
     */
    public function update(User $actor, User $employee, array $attributes): User
    {
        // Only admins may reassign role/department - a manager could otherwise
        // promote themselves or move an employee out of their own oversight.
        if (! $actor->isAdmin()) {
            unset($attributes['role'], $attributes['department_id']);
        }

        return $this->users->update($employee, $attributes);
    }

    public function delete(User $employee): void
    {
        $this->users->softDelete($employee);
    }
}
