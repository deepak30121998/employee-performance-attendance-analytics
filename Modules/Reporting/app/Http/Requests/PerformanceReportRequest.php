<?php

namespace Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('export-reports');
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }
}
