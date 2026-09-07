<?php

namespace Modules\Import\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Import\Models\ImportBatch;
use RuntimeException;

class DuplicateImportException extends RuntimeException
{
    public function __construct(
        private readonly ImportBatch $existingBatch,
    ) {
        parent::__construct("This file was already imported as batch #{$existingBatch->id}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'existing_batch_id' => $this->existingBatch->id,
            'existing_batch_status' => $this->existingBatch->status,
        ], 409);
    }
}
