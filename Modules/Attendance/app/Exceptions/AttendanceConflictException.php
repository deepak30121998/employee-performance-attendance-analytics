<?php

namespace Modules\Attendance\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

// routine 409s, no point cluttering the error log with them
class AttendanceConflictException extends RuntimeException implements ShouldntReport
{
    public static function alreadyCheckedIn(): self
    {
        return new self('An active check-in already exists for today. Check out before checking in again.');
    }

    public static function alreadyRecordedToday(): self
    {
        return new self('Attendance has already been recorded for today.');
    }

    public static function noActiveCheckIn(): self
    {
        return new self('No active check-in found for today.');
    }

    public static function alreadyCheckedOut(): self
    {
        return new self('You have already checked out today.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
