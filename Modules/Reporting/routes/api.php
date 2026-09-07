<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\ReportingController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('reports/attendance', [ReportingController::class, 'attendance']);
    Route::get('reports/performance', [ReportingController::class, 'performance']);
});
