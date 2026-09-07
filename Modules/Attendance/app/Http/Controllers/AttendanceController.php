<?php

namespace Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Attendance\Http\Requests\ListAttendanceRequest;
use Modules\Attendance\Http\Resources\AttendanceResource;
use Modules\Attendance\Models\Attendance;
use Modules\Attendance\Services\AttendanceService;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    public function checkIn(Request $request): JsonResponse
    {
        $attendance = $this->attendance->checkIn($request->user());

        return (new AttendanceResource($attendance))->response()->setStatusCode(201);
    }

    public function checkOut(Request $request): JsonResource
    {
        $attendance = $this->attendance->checkOut($request->user());

        return new AttendanceResource($attendance);
    }

    public function index(ListAttendanceRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Attendance::class);

        $attendances = $this->attendance->listFor($request->user(), $request->validated());

        return AttendanceResource::collection($attendances);
    }
}
