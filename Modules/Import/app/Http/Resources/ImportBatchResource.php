<?php

namespace Modules\Import\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_attendance_count' => $this->imported_attendance_count,
            'imported_performance_count' => $this->imported_performance_count,
            'skipped_row_count' => $this->skipped_row_count,
            'failed_row_count' => $this->failed_row_count,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'row_errors' => $this->whenLoaded('rowErrors', fn () => $this->rowErrors->map(fn ($e) => [
                'row_number' => $e->row_number,
                'reason' => $e->reason,
            ])),
        ];
    }
}
