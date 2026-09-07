<?php

namespace Modules\Import\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Attendance\Models\Attendance;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\ProcessAttendanceImportJob;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Support\AttendanceImportRowParser;
use Modules\Performance\Models\PerformanceScore;
use Modules\User\Models\Department;
use Tests\TestCase;

/**
 * Runs 20k rows by default (a full 500k on every CI run is just wasted
 * minutes); memory should stay flat regardless of row count since the file is
 * streamed, so a regression to "load the whole file" fails either way.
 * For the literal spec figure run:
 *
 *   LARGE_IMPORT_ROWS=500000 php artisan test --filter=LargeImportTest
 *
 * Measured on a dev laptop: 500,000 rows in 37.7s (13,267 rows/sec),
 * peak extra memory 4.0MB.
 */
class LargeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_a_large_file_within_a_bounded_memory_ceiling(): void
    {
        $totalRows = max(1000, (int) env('LARGE_IMPORT_ROWS', 20000));
        $employeeCount = 200;
        $rowsPerEmployee = intdiv($totalRows, $employeeCount);
        $totalRows = $employeeCount * $rowsPerEmployee;

        Storage::fake('local');

        $department = Department::factory()->create();
        $employees = User::factory()->count($employeeCount)->employee()->create(['department_id' => $department->id]);
        $admin = User::factory()->admin()->create();

        // written straight to disk so fixture generation stays flat too
        Storage::disk('local')->put('imports/large.csv', '');
        $path = Storage::disk('local')->path('imports/large.csv');
        $handle = fopen($path, 'w');
        fwrite($handle, "employee_email,date,check_in,check_out,performance\n");

        $date = Carbon::parse('2020-01-01');
        foreach ($employees as $employee) {
            $d = $date->copy();
            for ($i = 0; $i < $rowsPerEmployee; $i++) {
                fwrite($handle, "{$employee->email},{$d->toDateString()},09:00,18:00,\n");
                $d->addDay();
            }
        }
        fclose($handle);

        $batch = ImportBatch::create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'large.csv',
            'disk_path' => 'imports/large.csv',
            'checksum' => hash_file('sha256', $path),
            'status' => ImportStatus::Pending,
        ]);

        $memoryBefore = memory_get_usage(true);
        $startedAt = microtime(true);

        (new ProcessAttendanceImportJob($batch->id))->handle(app(AttendanceImportRowParser::class));

        $elapsed = microtime(true) - $startedAt;
        $memoryPeak = memory_get_peak_usage(true) - $memoryBefore;

        $batch->refresh();
        $this->assertEquals(ImportStatus::Completed, $batch->status);
        $this->assertSame($totalRows, $batch->processed_rows);
        $this->assertSame($totalRows, $batch->imported_attendance_count);
        $this->assertSame($totalRows, Attendance::count());
        $this->assertSame(0, PerformanceScore::count());

        // streaming + chunking means memory shouldn't scale with row count -
        // 64MB is generous headroom; "load the whole file" blows well past it
        $this->assertLessThan(64 * 1024 * 1024, $memoryPeak, 'Import memory usage should stay bounded regardless of row count.');

        fwrite(STDERR, sprintf(
            "\n[LargeImportTest] %s rows in %.2fs (%.0f rows/sec), peak extra memory %.1fMB\n",
            number_format($totalRows),
            $elapsed,
            $totalRows / max($elapsed, 0.001),
            $memoryPeak / 1024 / 1024,
        ));
    }
}
