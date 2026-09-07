<?php

namespace Modules\Import\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Exceptions\DuplicateImportException;
use Modules\Import\Jobs\ProcessAttendanceImportJob;
use Modules\Import\Models\ImportBatch;

class ImportService
{
    public function upload(User $admin, UploadedFile $file): ImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());

        if ($existing = ImportBatch::where('checksum', $checksum)->first()) {
            throw new DuplicateImportException($existing);
        }

        $path = $file->store('imports');

        $batch = ImportBatch::create([
            'uploaded_by' => $admin->id,
            'original_filename' => $file->getClientOriginalName(),
            'disk_path' => $path,
            'checksum' => $checksum,
            'status' => ImportStatus::Pending,
        ]);

        ProcessAttendanceImportJob::dispatch($batch->id);

        return $batch;
    }
}
