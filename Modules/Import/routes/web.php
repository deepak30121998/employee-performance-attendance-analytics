<?php

use Illuminate\Support\Facades\Route;
use Modules\Import\Http\Controllers\ImportController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('imports', ImportController::class)->names('import');
});
