<?php

namespace App\Services\ClientPortal;

use App\Domain\ClientPortal\Enums\ProjectDetailResolution;
use App\Domain\ClientPortal\Models\ProjectTaskCollectionLookup;
use App\Domain\ClientPortal\Models\ProjectTaskCollectionProjection;
use App\Domain\ClientPortal\Models\TaskActor;
use App\Domain\ClientPortal\Models\TaskLifecycle;
use App\Domain\ClientPortal\Models\TaskSummary;
use App\Domain\ClientPortal\ReadModels\TaskReadModel;
use App\Domain\ClientPortal\Repositories\TaskReadRepository;
use App\Services\Session\SessionData;
use App\Support\Api\Query\ListQuery;

class ClientTaskService
{
    public function __construct(
        private readonly ClientProjectService $projectService,
        private readonly TaskReadRepository $tasks,
    ) {
    }

    public function listForProject(
        SessionData $session,
        string $projectId,
        ListQuery $query,
        ?string $correlationId = null,
    ): ProjectTaskCollectionLookup {
        $projectLookup = $this->projectService->detail($session, $projectId, $correlationId);

        if ($projectLookup->project === null) {
            return new ProjectTaskCollectionLookup(
                collection: null,
                resolution: $projectLookup->resolution,
            );
        }

        $page = $this->tasks->paginateByProject($projectId, $query, $correlationId);

        return new ProjectTaskCollectionLookup(
            collection: new ProjectTaskCollectionProjection(
                project: $projectLookup->project,
                items: array_map(
                    fn (TaskReadModel $task): TaskSummary => $this->buildTaskSummary($task),
                    $page->items,
                ),
                total: $page->total,
            ),
            resolution: ProjectDetailResolution::Owned,
        );
    }

    private function buildTaskSummary(TaskReadModel $task): TaskSummary
    {
        return new TaskSummary(
            id: $task->id,
            title: $task->title,
            actor: new TaskActor(
                id: $task->actorId,
                email: $task->actorEmail,
                role: $task->actorRole,
            ),
            lifecycle: new TaskLifecycle(
                status: $task->status,
                priority: $task->priority,
                dueAt: $task->dueAt,
                completedAt: $task->completedAt,
                archived: $task->archived,
                createdAt: $task->createdAt,
                updatedAt: $task->updatedAt,
            ),
        );
    }
}