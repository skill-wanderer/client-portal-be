<?php

use App\Http\Controllers\Api\AuthorizationProbeController;
use App\Http\Controllers\Api\ClientDashboardController;
use App\Http\Controllers\Api\ClientProjectController;
use App\Http\Controllers\Api\ClientTaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/client')->group(function () {
    Route::middleware(['dashboard.audit', 'keycloak.token'])->group(function () {
        Route::get('/dashboard', [ClientDashboardController::class, 'show']);
    });

    Route::middleware(['projects.list.audit', 'bearer.validate', 'session.load', 'rbac:client'])->group(function () {
        Route::get('/projects', [ClientProjectController::class, 'index']);
    });

    Route::middleware(['projects.detail.audit', 'bearer.validate', 'session.load', 'rbac:client'])->group(function () {
        Route::get('/projects/{projectId}', [ClientProjectController::class, 'show']);
    });

    Route::middleware(['tasks.collection.audit', 'bearer.validate', 'session.load', 'rbac:client'])->group(function () {
        Route::get('/projects/{projectId}/tasks', [ClientTaskController::class, 'index']);
    });

    Route::middleware(['bearer.validate', 'session.load', 'rbac:client'])->group(function () {
        Route::post('/projects/{projectId}/tasks', [ClientTaskController::class, 'store']);
        Route::patch('/projects/{projectId}/tasks/{taskId}/status', [ClientTaskController::class, 'updateStatus']);
    });

    Route::middleware(['bearer.validate', 'session.load', 'rbac:client'])->group(function () {
        Route::get('/users/{userId}', [AuthorizationProbeController::class, 'clientUser'])->middleware('owner');
    });
});

