<?php

use Illuminate\Support\Facades\Route;
use Modules\Performance\Http\Controllers\PerformanceController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('performance', [PerformanceController::class, 'store']);
    Route::get('performance', [PerformanceController::class, 'index']);
});
