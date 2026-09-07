<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Enums\Role;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($employee->id)],
            'role' => ['sometimes', Rule::enum(Role::class)],
            // not nullable - an update must never strip a non-admin's department
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'designation' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
