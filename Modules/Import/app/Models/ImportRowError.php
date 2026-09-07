<?php

namespace Modules\Import\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowError extends Model
{
    protected $fillable = [
        'import_batch_id',
        'row_number',
        'reason',
        'raw_row',
    ];

    protected function casts(): array
    {
        return [
            'raw_row' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
