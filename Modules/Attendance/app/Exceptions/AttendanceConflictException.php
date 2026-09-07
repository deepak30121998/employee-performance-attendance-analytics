<?php

namespace Modules\Attendance\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AttendanceConflictException extends RuntimeException
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
