<?php

namespace Modules\Import\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Import\Models\ImportBatch;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ImportBatch::class);
    }

    public function rules(): array
    {
        return [
            // 100MB covers 500k+ rows; php.ini upload limits must match (see README)
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:102400'],
        ];
    }
}
