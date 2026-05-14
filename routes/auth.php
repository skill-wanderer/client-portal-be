<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::get('/login', [AuthController::class, 'login']);
    Route::get('/callback', [AuthController::class, 'callback']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('session.load');
});