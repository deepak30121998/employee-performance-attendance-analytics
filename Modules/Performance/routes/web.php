<?php

use Illuminate\Support\Facades\Route;
use Modules\Performance\Http\Controllers\PerformanceController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('performances', PerformanceController::class)->names('performance');
});
