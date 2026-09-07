<?php

namespace Modules\Performance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer'],
            'month' => ['sometimes', 'date_format:Y-m'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();

        // scores are stored as first-of-month dates
        if (! empty($filters['month'])) {
            $filters['month'] .= '-01';
        }

        return $filters;
    }
}
