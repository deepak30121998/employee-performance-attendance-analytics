<?php

namespace Modules\Performance\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class PerformanceScoreConflictException extends RuntimeException
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
