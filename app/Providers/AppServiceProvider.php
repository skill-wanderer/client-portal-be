<?php

namespace App\Providers;

use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Contracts\ProjectWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\TaskIdGenerator;
use App\Domain\ClientPortal\Write\Contracts\TaskWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WorkspaceWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WriteTransactionManager;
use App\Domain\ClientPortal\Repositories\ProjectReadRepository;
use App\Domain\ClientPortal\Repositories\TaskReadRepository;
use App\Infrastructure\ClientPortal\Write\DatabaseMutationEventRecorder;
use App\Infrastructure\ClientPortal\Write\DatabaseMutationIdempotencyStore;
use App\Infrastructure\ClientPortal\Write\DatabaseWriteTransactionManager;
use App\Infrastructure\ClientPortal\Write\DeterministicTaskIdGenerator;
use App\Infrastructure\ClientPortal\Write\EloquentProjectWriteRepository;
use App\Infrastructure\ClientPortal\Write\EloquentTaskWriteRepository;
use App\Infrastructure\ClientPortal\Write\EloquentWorkspaceWriteRepository;
use App\Infrastructure\ClientPortal\Repositories\EloquentProjectReadRepository;
use App\Infrastructure\ClientPortal\Repositories\EloquentTaskReadRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProjectReadRepository::class, EloquentProjectReadRepository::class);
        $this->app->bind(TaskReadRepository::class, EloquentTaskReadRepository::class);
        $this->app->bind(WorkspaceWriteRepository::class, EloquentWorkspaceWriteRepository::class);
        $this->app->bind(ProjectWriteRepository::class, EloquentProjectWriteRepository::class);
        $this->app->bind(TaskWriteRepository::class, EloquentTaskWriteRepository::class);
        $this->app->bind(TaskIdGenerator::class, DeterministicTaskIdGenerator::class);
        $this->app->bind(MutationIdempotencyStore::class, DatabaseMutationIdempotencyStore::class);
        $this->app->bind(MutationEventRecorder::class, DatabaseMutationEventRecorder::class);
        $this->app->bind(WriteTransactionManager::class, DatabaseWriteTransactionManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
