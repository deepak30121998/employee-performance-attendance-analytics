<?php

namespace Modules\Performance\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PerformanceScoreConflictException extends RuntimeException implements ShouldntReport
{
    public static function alreadyRecordedForMonth(): self
    {
        return new self('A performance score has already been recorded for this employee for this month.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
