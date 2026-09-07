<?php

namespace Modules\Performance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee->name),
            'month' => $this->month->format('Y-m'),
            'score' => $this->score,
            'comment' => $this->comment,
            'created_by' => $this->created_by,
        ];
    }
}
