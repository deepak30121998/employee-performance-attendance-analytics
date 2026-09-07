<?php

namespace Modules\User\DTOs;

use Modules\User\Enums\Role;

final readonly class EmployeeData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $password,
        public Role $role,
        public ?int $departmentId,
        public ?string $designation,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: $validated['name'],
            email: $validated['email'],
            password: $validated['password'] ?? null,
            role: Role::from($validated['role']),
            departmentId: $validated['department_id'] ?? null,
            designation: $validated['designation'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'role' => $this->role,
            'department_id' => $this->departmentId,
            'designation' => $this->designation,
        ];
    }
}
