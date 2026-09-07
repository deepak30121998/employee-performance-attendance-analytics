<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Reporting\Http\Requests\AttendanceReportRequest;
use Modules\Reporting\Http\Requests\PerformanceReportRequest;
use Modules\Reporting\Services\ReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    public function attendance(AttendanceReportRequest $request): StreamedResponse
    {
        return $this->reports->attendanceCsv($request->validated('from'), $request->validated('to'));
    }

    public function performance(PerformanceReportRequest $request): StreamedResponse
    {
        return $this->reports->performanceCsv($request->validated('month').'-01');
    }
}
