<?php

namespace Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Performance\Http\Requests\StorePerformanceScoreRequest;
use Modules\Performance\Http\Resources\PerformanceScoreResource;
use Modules\Performance\Models\PerformanceScore;
use Modules\Performance\Services\PerformanceService;

class PerformanceController extends Controller
{
    public function __construct(
        private readonly PerformanceService $performance,
    ) {}

    public function store(StorePerformanceScoreRequest $request): JsonResource
    {
        $employee = User::findOrFail($request->validated('employee_id'));

        $this->authorize('create', [PerformanceScore::class, $employee]);

        $score = $this->performance->record(
            manager: $request->user(),
            employeeId: $employee->id,
            month: $request->normalizedMonth(),
            score: $request->validated('score'),
            comment: $request->validated('comment'),
        );

        return new PerformanceScoreResource($score);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PerformanceScore::class);

        $scores = $this->performance->listFor($request->user(), $request->only(['employee_id', 'month']));

        return PerformanceScoreResource::collection($scores);
    }
}
