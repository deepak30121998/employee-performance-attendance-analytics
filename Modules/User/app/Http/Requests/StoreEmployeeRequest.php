<?php

namespace Modules\User\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Enums\Role;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        // department/designation are required employee data per spec - except for
        // admin accounts, which don't belong to a department in this system.
        $requiredUnlessAdmin = $this->input('role') === Role::Admin->value ? 'nullable' : 'required';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::enum(Role::class)],
            'department_id' => [$requiredUnlessAdmin, 'exists:departments,id'],
            'designation' => [$requiredUnlessAdmin, 'string', 'max:255'],
        ];
    }
}
