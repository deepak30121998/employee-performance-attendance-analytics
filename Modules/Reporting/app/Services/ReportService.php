<?php

namespace Modules\Reporting\Services;

use Modules\Attendance\Contracts\AttendanceRepositoryInterface;
use Modules\Performance\Contracts\PerformanceRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService
{
    public function __construct(
        private readonly AttendanceRepositoryInterface $attendance,
        private readonly PerformanceRepositoryInterface $performance,
    ) {}

    public function attendanceCsv(string $from, string $to): StreamedResponse
    {
        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['employee_email', 'employee_name', 'date', 'check_in', 'check_out', 'working_minutes', 'status']);

            // cursor() reads one row at a time from the DB driver - the result
            // set is never materialized in memory regardless of its size.
            foreach ($this->attendance->cursorForRange($from, $to) as $row) {
                fputcsv($out, [
                    $row->employee_email,
                    $row->employee_name,
                    $row->date instanceof \DateTimeInterface ? $row->date->format('Y-m-d') : $row->date,
                    $row->check_in_at,
                    $row->check_out_at,
                    $row->working_minutes,
                    $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                ]);
            }

            fclose($out);
        }, "attendance-{$from}-to-{$to}.csv");
    }

    public function performanceCsv(string $month): StreamedResponse
    {
        return response()->streamDownload(function () use ($month) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['employee_email', 'employee_name', 'month', 'score', 'comment']);

            foreach ($this->performance->cursorForMonth($month) as $row) {
                fputcsv($out, [$row->employee_email, $row->employee_name, $month, $row->score, $row->comment]);
            }

            fclose($out);
        }, "performance-{$month}.csv");
    }
}
