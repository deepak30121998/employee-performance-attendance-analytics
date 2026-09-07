<?php

namespace Modules\Import\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Models\Attendance;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\ProcessAttendanceImportJob;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Support\AttendanceImportRowParser;
use Modules\Performance\Models\PerformanceScore;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csvFile(string $content, string $name = 'import.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_admin_can_upload_and_valid_rows_are_imported(): void
    {
        $admin = User::factory()->admin()->create();
        $rahul = User::factory()->employee()->create(['email' => 'rahul@test.com']);
        $amit = User::factory()->employee()->create(['email' => 'amit@test.com']);

        $csv = "employee_email,date,check_in,check_out,performance\n"
            ."rahul@test.com,2026-08-01,09:55,18:30,8\n"
            ."amit@test.com,2026-08-01,10:10,19:00,7\n"
            ."ghost@test.com,2026-08-01,09:00,18:00,5\n" // employee not found
            ."rahul@test.com,not-a-date,09:00,18:00,5\n"  // invalid date
            ."amit@test.com,2026-08-02,18:00,09:00,5\n";  // invalid check-in/check-out sequence

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/import', [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertStatus(202);

        $batch = ImportBatch::first();
        $this->assertEquals(ImportStatus::CompletedWithErrors, $batch->status);
        $this->assertEquals(2, $batch->imported_attendance_count);
        $this->assertEquals(2, $batch->imported_performance_count);
        $this->assertEquals(3, $batch->failed_row_count);
        $this->assertEquals(5, $batch->processed_rows);

        $this->assertDatabaseHas('attendances', ['employee_id' => $rahul->id, 'date' => '2026-08-01', 'source' => 'import']);
        $this->assertDatabaseHas('import_row_errors', ['reason' => 'employee not found']);
        $this->assertDatabaseHas('import_row_errors', ['reason' => 'invalid date']);
        $this->assertDatabaseHas('import_row_errors', ['reason' => 'invalid check-in/check-out sequence']);
    }

    public function test_manual_attendance_is_not_overwritten_by_import(): void
    {
        $admin = User::factory()->admin()->create();
        $rahul = User::factory()->employee()->create(['email' => 'rahul@test.com']);

        Attendance::factory()->create([
            'employee_id' => $rahul->id,
            'date' => '2026-08-01',
            'source' => AttendanceSource::Manual,
        ]);

        $csv = "employee_email,date,check_in,check_out,performance\n"
            ."rahul@test.com,2026-08-01,09:55,18:30,8\n";

        $this->actingAs($admin, 'sanctum')->postJson('/api/import', ['file' => $this->csvFile($csv)]);

        $batch = ImportBatch::first();
        $this->assertEquals(1, $batch->failed_row_count);
        $this->assertDatabaseHas('import_row_errors', ['reason' => 'duplicate attendance']);
        $this->assertSame(1, Attendance::where('employee_id', $rahul->id)->count());
    }

    public function test_employee_cannot_upload_an_import(): void
    {
        $employee = User::factory()->employee()->create();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/import', ['file' => $this->csvFile("employee_email,date,check_in,check_out,performance\n")])
            ->assertForbidden();
    }

    public function test_uploading_the_same_file_twice_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->employee()->create(['email' => 'rahul@test.com']);

        $csv = "employee_email,date,check_in,check_out,performance\nrahul@test.com,2026-08-01,09:00,18:00,8\n";

        $this->actingAs($admin, 'sanctum')->postJson('/api/import', ['file' => $this->csvFile($csv)])->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->postJson('/api/import', ['file' => $this->csvFile($csv)])->assertStatus(409);

        $this->assertSame(1, ImportBatch::count());
    }

    public function test_retrying_a_full_reprocess_does_not_duplicate_rows(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->employee()->create(['email' => 'rahul@test.com']);

        $csv = "employee_email,date,check_in,check_out,performance\nrahul@test.com,2026-08-01,09:00,18:00,8\n";

        $this->actingAs($admin, 'sanctum')->postJson('/api/import', ['file' => $this->csvFile($csv)]);

        $batch = ImportBatch::first();
        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, PerformanceScore::count());

        // simulate a worker crash that re-runs the whole job from scratch
        $batch->update(['status' => ImportStatus::Pending, 'last_processed_row' => 0]);
        (new ProcessAttendanceImportJob($batch->id))->handle(app(AttendanceImportRowParser::class));

        $this->assertSame(1, Attendance::count(), 'A full re-run must not create a duplicate attendance row.');
        $this->assertSame(1, PerformanceScore::count(), 'A full re-run must not create a duplicate performance row.');
    }

    public function test_a_retried_job_resumes_from_last_processed_row_instead_of_reparsing_earlier_rows(): void
    {
        User::factory()->admin()->create(['id' => 999]);
        $rahul = User::factory()->employee()->create(['email' => 'rahul@test.com']);
        $amit = User::factory()->employee()->create(['email' => 'amit@test.com']);

        // row 2 would fail with "employee not found" if parsed - it's here to
        // prove the resume skip actually skips
        $csv = "employee_email,date,check_in,check_out,performance\n"
            ."ghost@test.com,2026-08-01,09:00,18:00,5\n"     // row 2 - must be skipped, never parsed
            ."rahul@test.com,2026-08-02,09:00,18:00,8\n"     // row 3
            ."amit@test.com,2026-08-03,09:00,18:00,7\n";     // row 4

        // built directly, not via the endpoint - the sync queue would already
        // process the whole file and this test needs the first handle() to be a resume
        Storage::disk('local')->put('imports/resume-test.csv', $csv);
        $batch = ImportBatch::create([
            'uploaded_by' => 999,
            'original_filename' => 'resume-test.csv',
            'disk_path' => 'imports/resume-test.csv',
            'checksum' => hash('sha256', $csv),
            'status' => ImportStatus::Processing,
            'last_processed_row' => 2, // simulates: an earlier attempt already committed through row 2
            'processed_rows' => 2,
        ]);

        (new ProcessAttendanceImportJob($batch->id))->handle(app(AttendanceImportRowParser::class));

        $batch->refresh();
        $this->assertEquals(ImportStatus::Completed, $batch->status);
        $this->assertSame(4, $batch->processed_rows, '2 already-accounted-for + 2 newly processed.');
        $this->assertSame(2, $batch->imported_attendance_count);
        $this->assertDatabaseMissing('import_row_errors', ['reason' => 'employee not found']);
        $this->assertDatabaseHas('attendances', ['employee_id' => $rahul->id, 'date' => '2026-08-02']);
        $this->assertDatabaseHas('attendances', ['employee_id' => $amit->id, 'date' => '2026-08-03']);
    }
}
