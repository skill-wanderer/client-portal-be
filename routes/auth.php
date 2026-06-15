<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RuntimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::get('/runtime/health', [RuntimeController::class, 'health']);
    Route::get('/runtime/deployment', [RuntimeController::class, 'deployment']);
    Route::get('/login', [AuthController::class, 'login']);
    Route::get('/callback', [AuthController::class, 'callback']);
    Route::get('/me', [AuthController::class, 'me'])->middleware(['bearer.validate', 'session.load']);
});
