<?php

namespace Modules\Import\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Import\Http\Requests\StoreImportRequest;
use Modules\Import\Http\Resources\ImportBatchResource;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Services\ImportService;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
    ) {}

    public function store(StoreImportRequest $request): JsonResponse
    {
        $batch = $this->imports->upload($request->user(), $request->file('file'));

        return (new ImportBatchResource($batch))->response()->setStatusCode(202);
    }

    public function index(Request $request): JsonResource
    {
        $this->authorize('viewAny', ImportBatch::class);

        $batches = ImportBatch::query()->orderByDesc('id')->cursorPaginate(25);

        return ImportBatchResource::collection($batches);
    }

    public function show(ImportBatch $import): ImportBatchResource
    {
        $this->authorize('viewAny', ImportBatch::class);

        return new ImportBatchResource($import->load('rowErrors'));
    }
}
