<?php

use App\Http\Controllers\Api\RuntimeTestController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    Route::get('/test-db', [RuntimeTestController::class, 'database']);
});
