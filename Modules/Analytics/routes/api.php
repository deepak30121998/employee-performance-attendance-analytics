<?php

use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\AnalyticsController;

Route::middleware('auth:sanctum')->get('analytics', [AnalyticsController::class, 'index']);
