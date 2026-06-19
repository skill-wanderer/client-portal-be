<?php

use App\Http\Controllers\Api\RuntimeTestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    Route::get('/health', [RuntimeTestController::class, 'health']);
    Route::get('/test-db', [RuntimeTestController::class, 'database']);
});
