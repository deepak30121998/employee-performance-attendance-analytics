<?php

namespace Modules\Import\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Exceptions\DuplicateImportException;
use Modules\Import\Jobs\ProcessAttendanceImportJob;
use Modules\Import\Models\ImportBatch;

class ImportService
{
    private const MYSQL_DUPLICATE_KEY_ERRNO = 1062;

    public function upload(User $admin, UploadedFile $file): ImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());

        if ($existing = ImportBatch::where('checksum', $checksum)->first()) {
            throw new DuplicateImportException($existing);
        }

        $path = $file->store('imports');

        // two simultaneous uploads of the same file can both pass the check
        // above; the unique checksum column picks the loser, 409 not 500
        try {
            $batch = ImportBatch::create([
                'uploaded_by' => $admin->id,
                'original_filename' => $file->getClientOriginalName(),
                'disk_path' => $path,
                'checksum' => $checksum,
                'status' => ImportStatus::Pending,
            ]);
        } catch (QueryException $e) {
            if (((int) ($e->errorInfo[1] ?? 0)) === self::MYSQL_DUPLICATE_KEY_ERRNO) {
                throw new DuplicateImportException(ImportBatch::where('checksum', $checksum)->firstOrFail());
            }

            throw $e;
        }

        ProcessAttendanceImportJob::dispatch($batch->id);

        return $batch;
    }

    public function listBatches(): CursorPaginator
    {
        return ImportBatch::query()->orderByDesc('id')->cursorPaginate(25);
    }
}
