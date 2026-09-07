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
 * 20k rows instead of a literal 500k (which is just CI minutes for nothing) -
 * enough to catch a memory regression, since the streamed pipeline should stay
 * flat no matter the row count. If someone swaps fgetcsv for file(), this fails.
 */
class LargeImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_20000_rows_within_a_bounded_memory_ceiling(): void
    {
        Storage::fake('local');

        $department = Department::factory()->create();
        $employees = User::factory()->count(200)->employee()->create(['department_id' => $department->id]);
        $admin = User::factory()->admin()->create();

        $rows = ['employee_email,date,check_in,check_out,performance'];
        $date = Carbon::parse('2025-01-01');
        $rowsPerEmployee = 100; // 200 employees * 100 = 20,000 rows

        foreach ($employees as $employee) {
            $d = $date->copy();
            for ($i = 0; $i < $rowsPerEmployee; $i++) {
                $rows[] = "{$employee->email},{$d->toDateString()},09:00,18:00,";
                $d->addDay();
            }
        }

        $csv = implode("\n", $rows)."\n";
        Storage::disk('local')->put('imports/large.csv', $csv);

        $batch = ImportBatch::create([
            'uploaded_by' => $admin->id,
            'original_filename' => 'large.csv',
            'disk_path' => 'imports/large.csv',
            'checksum' => hash('sha256', $csv),
            'status' => ImportStatus::Pending,
        ]);

        $memoryBefore = memory_get_usage(true);
        $startedAt = microtime(true);

        (new ProcessAttendanceImportJob($batch->id))->handle(app(AttendanceImportRowParser::class));

        $elapsed = microtime(true) - $startedAt;
        $memoryPeak = memory_get_peak_usage(true) - $memoryBefore;

        $batch->refresh();
        $this->assertEquals(ImportStatus::Completed, $batch->status);
        $this->assertSame(20000, $batch->processed_rows);
        $this->assertSame(20000, $batch->imported_attendance_count);
        $this->assertSame(20000, Attendance::count());
        $this->assertSame(0, PerformanceScore::count());

        // Streaming + chunking means memory shouldn't scale with row count -
        // 64MB is generous headroom for a 20k-row run; a regression to
        // "load the whole file" would blow well past this on a much smaller file.
        $this->assertLessThan(64 * 1024 * 1024, $memoryPeak, 'Import memory usage should stay bounded regardless of row count.');

        fwrite(STDERR, sprintf(
            "\n[LargeImportTest] 20,000 rows in %.2fs (%.0f rows/sec), peak extra memory %.1fMB\n",
            $elapsed,
            20000 / max($elapsed, 0.001),
            $memoryPeak / 1024 / 1024,
        ));
    }
}
