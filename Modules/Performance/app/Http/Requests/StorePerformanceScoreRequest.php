<?php

namespace Modules\Performance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Performance\Models\PerformanceScore;

class StorePerformanceScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:users,id'],
            'month' => ['required', 'date_format:Y-m'],
            'score' => ['required', 'integer', 'between:'.PerformanceScore::MIN_SCORE.','.PerformanceScore::MAX_SCORE],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function normalizedMonth(): string
    {
        return $this->validated('month').'-01';
    }
}
