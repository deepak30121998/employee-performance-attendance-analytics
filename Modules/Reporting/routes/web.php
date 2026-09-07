<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\ReportingController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('reportings', ReportingController::class)->names('reporting');
});
