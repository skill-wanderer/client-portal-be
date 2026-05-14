<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Write\Contracts\ProjectWriteRepository;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Support\StaleWriteException;
use App\Models\ClientProject;
use Illuminate\Support\Str;

final class EloquentProjectWriteRepository implements ProjectWriteRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function findOwnedProject(string $workspaceId, string $projectId): ?ProjectAggregateState
    {
        /** @var ClientProject|null $project */
        $project = ClientProject::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $projectId)
            ->first();

        if (! $project instanceof ClientProject) {
            return null;
        }

        return new ProjectAggregateState(
            id: (string) $project->id,
            workspaceId: (string) $project->workspace_id,
            status: ProjectStatus::from((string) $project->status),
            visibility: ProjectVisibility::from((string) $project->visibility),
            archived: (bool) $project->archived,
            version: (int) $project->version,
            taskCount: (int) $project->task_count,
            activeTaskCount: (int) $project->active_task_count,
            completedTaskCount: (int) $project->completed_task_count,
            pendingActionCount: (int) $project->pending_action_count,
            fileCount: (int) $project->file_count,
            name: (string) $project->name,
            description: (string) $project->description,
        );
    }

    public function add(ProjectAggregateState $project): void
    {
        ClientProject::query()->create([
            'id' => $project->id,
            'workspace_id' => $project->workspaceId,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'visibility' => $project->visibility->value,
            'archived' => $project->archived,
            'task_count' => $project->taskCount,
            'active_task_count' => $project->activeTaskCount,
            'file_count' => $project->fileCount,
            'pending_action_count' => $project->pendingActionCount,
            'completed_task_count' => $project->completedTaskCount,
            'version' => $project->version,
        ]);
    }

    public function save(ProjectAggregateState $project, int $expectedVersion): void
    {
        $updated = ClientProject::query()
            ->where('id', $project->id)
            ->where('workspace_id', $project->workspaceId)
            ->where('version', $expectedVersion)
            ->update([
                'status' => $project->status->value,
                'visibility' => $project->visibility->value,
                'archived' => $project->archived,
                'task_count' => $project->taskCount,
                'active_task_count' => $project->activeTaskCount,
                'file_count' => $project->fileCount,
                'pending_action_count' => $project->pendingActionCount,
                'completed_task_count' => $project->completedTaskCount,
                'version' => $project->version,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $currentVersion = ClientProject::query()->where('id', $project->id)->value('version');

            throw new StaleWriteException(
                aggregateId: $project->id,
                expectedVersion: $expectedVersion,
                currentVersion: is_numeric($currentVersion) ? (int) $currentVersion : null,
            );
        }
    }
}