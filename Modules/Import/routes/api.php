<?php

use Illuminate\Support\Facades\Route;
use Modules\Import\Http\Controllers\ImportController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('import', [ImportController::class, 'store']);
    Route::get('import', [ImportController::class, 'index']);
    Route::get('import/{import}', [ImportController::class, 'show']);
});
