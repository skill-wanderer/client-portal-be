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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$trustedProxies = trim((string) env('TRUSTED_PROXIES', 'REMOTE_ADDR'));

if ($trustedProxies === '*' || $trustedProxies === '**') {
    $resolvedTrustedProxies = $trustedProxies;
} else {
    $resolvedTrustedProxies = array_values(array_filter(
        array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', $trustedProxies)
        ),
        static fn (string $proxy): bool => $proxy !== ''
    ));
}

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
    ->withMiddleware(function (Middleware $middleware) use ($resolvedTrustedProxies) {
        $middleware->trustProxies(
            at: $resolvedTrustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

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
