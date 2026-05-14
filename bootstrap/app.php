<?php

use App\Http\Middleware\DashboardAuditMiddleware;
use App\Http\Middleware\OwnershipMiddleware;
use App\Http\Middleware\ProjectsDetailAuditMiddleware;
use App\Http\Middleware\ProjectsListAuditMiddleware;
use App\Http\Middleware\RBACMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Middleware\TasksCollectionAuditMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/auth.php'));
            Route::group([], base_path('routes/api_client.php'));
            Route::group([], base_path('routes/api_admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'dashboard.audit' => DashboardAuditMiddleware::class,
            'projects.detail.audit' => ProjectsDetailAuditMiddleware::class,
            'projects.list.audit' => ProjectsListAuditMiddleware::class,
            'session.load' => SessionMiddleware::class,
            'tasks.collection.audit' => TasksCollectionAuditMiddleware::class,
            'rbac' => RBACMiddleware::class,
            'owner' => OwnershipMiddleware::class,
            'ownership' => OwnershipMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
