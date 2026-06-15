<?php

use App\Http\Controllers\Api\AuthorizationProbeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/admin')->middleware(['bearer.validate', 'session.load', 'rbac:admin'])->group(function () {
    Route::get('/users/{userId}', [AuthorizationProbeController::class, 'adminUser']);
});
