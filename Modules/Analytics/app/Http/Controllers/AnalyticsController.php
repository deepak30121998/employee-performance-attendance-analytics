<?php

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Analytics\Http\Requests\AnalyticsRequest;
use Modules\Analytics\Services\AnalyticsService;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
    ) {}

    public function index(AnalyticsRequest $request): JsonResponse
    {
        $month = $request->string('month')->toString() ?: Carbon::now()->format('Y-m');

        return response()->json([
            'data' => $this->analytics->forUser($request->user(), $month),
        ]);
    }
}
