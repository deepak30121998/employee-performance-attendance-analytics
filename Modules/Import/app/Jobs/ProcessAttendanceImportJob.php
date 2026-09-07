<?php

namespace Modules\Import\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Analytics\Support\AnalyticsCache;
use Modules\Attendance\Enums\AttendanceSource;
use Modules\Attendance\Enums\AttendanceStatus;
use Modules\Import\DTOs\ParsedImportRow;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Support\AttendanceImportRowParser;

class ProcessAttendanceImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 500k rows = a few minutes of I/O-bound work, well under this ceiling
    public int $timeout = 1800;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly int $importBatchId,
    ) {}

    public function handle(AttendanceImportRowParser $parser): void
    {
        $batch = ImportBatch::findOrFail($this->importBatchId);

        if (in_array($batch->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true)) {
            // stray retry after the batch already finished
            return;
        }

        $batch->update(['status' => ImportStatus::Processing, 'started_at' => $batch->started_at ?? now()]);

        $fullPath = Storage::disk('local')->path($batch->disk_path);
        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            $batch->update([
                'status' => ImportStatus::Failed,
                'failure_reason' => 'Could not open the uploaded file.',
                'finished_at' => now(),
            ]);

            return;
        }

        try {
            $this->processFile($batch, $parser, $handle);
        } catch (\Throwable $e) {
            Log::error('Attendance import job failed', [
                'import_batch_id' => $batch->id,
                'exception' => $e->getMessage(),
            ]);

            $batch->update([
                'status' => ImportStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function processFile(ImportBatch $batch, AttendanceImportRowParser $parser, $handle): void
    {
        $header = fgetcsv($handle);
        $rowNumber = 1; // the header line itself

        // on retry, skip rows already committed by an earlier attempt - just a
        // speedup, the per-chunk duplicate checks keep it correct either way
        $resumeAfter = $batch->last_processed_row;

        $chunk = [];

        while (($line = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if ($rowNumber <= $resumeAfter) {
                continue;
            }

            $chunk[$rowNumber] = array_combine($header, $line);

            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->processChunk($batch, $parser, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->processChunk($batch, $parser, $chunk);
        }

        $batch->refresh();
        $batch->update([
            'status' => $batch->failed_row_count > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
            'total_rows' => $batch->processed_rows,
            'finished_at' => now(),
        ]);

        // bulk writes above bypass Eloquent, so the observers never fired
        app(AnalyticsCache::class)->flush();
    }

    /**
     * @param  array<int, array<string, string|null>>  $chunk  row_number => raw row
     */
    private function processChunk(ImportBatch $batch, AttendanceImportRowParser $parser, array $chunk): void
    {
        DB::transaction(function () use ($batch, $parser, $chunk) {
            $parsed = [];
            foreach ($chunk as $rowNumber => $row) {
                $parsed[] = $parser->parse($rowNumber, $row);
            }

            $validRows = collect($parsed)->filter->isValid();
            $employeesByEmail = User::query()
                ->whereIn('email', $validRows->pluck('employeeEmail')->unique())
                ->get(['id', 'email', 'department_id'])
                ->keyBy('email');

            $employeeIds = $employeesByEmail->pluck('id')->unique()->values()->all();

            // one prefetch per chunk per table, so the writes below stay bulk INSERTs
            $existingAttendance = $this->existingAttendanceKeys($employeeIds, $validRows);
            $existingPerformance = $this->existingPerformanceKeys($employeeIds, $validRows);

            $rowErrors = [];
            $newAttendanceRows = [];
            $newPerformanceRows = [];
            $importedAttendance = 0;
            $importedPerformance = 0;
            $skipped = 0;
            $now = now();

            foreach ($parsed as $row) {
                if (! $row->isValid()) {
                    $rowErrors[] = $this->rowErrorPayload($batch->id, $row->rowNumber, $row->error, $row->raw);

                    continue;
                }

                $employee = $employeesByEmail[$row->employeeEmail] ?? null;

                if ($employee === null) {
                    $rowErrors[] = $this->rowErrorPayload($batch->id, $row->rowNumber, 'employee not found', $row->raw);

                    continue;
                }

                if ($row->hasAttendance()) {
                    $key = $employee->id.':'.$row->date;
                    $existingSource = $existingAttendance[$key] ?? null;

                    if ($existingSource === null) {
                        $newAttendanceRows[] = [
                            'employee_id' => $employee->id,
                            'department_id' => $employee->department_id,
                            'date' => $row->date,
                            'check_in_at' => $row->checkIn,
                            'check_out_at' => $row->checkOut,
                            'working_minutes' => (int) ((strtotime($row->checkOut) - strtotime($row->checkIn)) / 60),
                            'status' => AttendanceStatus::Present->value,
                            'source' => AttendanceSource::Import->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        // claim it now, so a dup within this same chunk doesn't
                        // blow up the bulk insert and roll back valid rows with it
                        $existingAttendance[$key] = AttendanceSource::Import->value;
                        $importedAttendance++;
                    } elseif ($existingSource === AttendanceSource::Import->value) {
                        // a prior run of this import already wrote it, no-op
                        $skipped++;
                    } else {
                        // A real manual check-in - importing must never silently overwrite it.
                        $rowErrors[] = $this->rowErrorPayload($batch->id, $row->rowNumber, 'duplicate attendance', $row->raw);
                    }
                }

                if ($row->hasPerformance()) {
                    // The parser guarantees $row->date is set whenever a score is present.
                    $month = substr($row->date, 0, 7).'-01';
                    $key = $employee->id.':'.$month;

                    if (! isset($existingPerformance[$key])) {
                        $newPerformanceRows[] = [
                            'employee_id' => $employee->id,
                            'month' => $month,
                            'score' => $row->score,
                            'created_by' => $batch->uploaded_by,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $existingPerformance[$key] = true;
                        $importedPerformance++;
                    } else {
                        $skipped++;
                    }
                }
            }

            if ($newAttendanceRows !== []) {
                DB::table('attendances')->insert($newAttendanceRows);
            }

            if ($newPerformanceRows !== []) {
                DB::table('performance_scores')->insert($newPerformanceRows);
            }

            if ($rowErrors !== []) {
                DB::table('import_row_errors')->insert($rowErrors);
            }

            $lastRowNumber = max(array_keys($chunk));

            $batch->increment('processed_rows', count($chunk));
            $batch->increment('imported_attendance_count', $importedAttendance);
            $batch->increment('imported_performance_count', $importedPerformance);
            $batch->increment('skipped_row_count', $skipped);
            $batch->increment('failed_row_count', count($rowErrors));
            $batch->update(['last_processed_row' => $lastRowNumber]);
        });
    }

    /**
     * @param  array<int>  $employeeIds
     * @param  Collection<int, ParsedImportRow>  $validRows
     * @return array<string, string> "{employee_id}:{date}" => source
     */
    private function existingAttendanceKeys(array $employeeIds, $validRows): array
    {
        $dates = $validRows->filter->hasAttendance()->pluck('date')->unique()->values()->all();

        if ($employeeIds === [] || $dates === []) {
            return [];
        }

        return DB::table('attendances')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('date', $dates)
            ->get(['employee_id', 'date', 'source'])
            ->mapWithKeys(fn ($r) => ["{$r->employee_id}:{$r->date}" => $r->source])
            ->all();
    }

    /**
     * @param  array<int>  $employeeIds
     * @param  Collection<int, ParsedImportRow>  $validRows
     * @return array<string, bool> "{employee_id}:{month}" => true
     */
    private function existingPerformanceKeys(array $employeeIds, $validRows): array
    {
        $months = $validRows->filter->hasPerformance()
            ->map(fn ($row) => substr($row->date, 0, 7).'-01')
            ->unique()
            ->values()
            ->all();

        if ($employeeIds === [] || $months === []) {
            return [];
        }

        return DB::table('performance_scores')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('month', $months)
            ->get(['employee_id', 'month'])
            ->mapWithKeys(fn ($r) => ["{$r->employee_id}:{$r->month}" => true])
            ->all();
    }

    /**
     * @param  array<string, string|null>  $raw
     * @return array<string, mixed>
     */
    private function rowErrorPayload(int $importBatchId, int $rowNumber, string $reason, array $raw): array
    {
        return [
            'import_batch_id' => $importBatchId,
            'row_number' => $rowNumber,
            'reason' => $reason,
            'raw_row' => json_encode($raw),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
