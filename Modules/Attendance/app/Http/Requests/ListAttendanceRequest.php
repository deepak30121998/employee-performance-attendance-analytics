<?php

namespace Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Attendance\Enums\AttendanceStatus;

class ListAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // role scoping happens in the repository
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', Rule::enum(AttendanceStatus::class)],
        ];
    }
}
