<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Contracts\TaskWriteRepository;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Support\StaleWriteException;
use App\Models\ClientTask;
use Illuminate\Support\Str;

final class EloquentTaskWriteRepository implements TaskWriteRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::uuid();
    }

    public function findOwnedTask(string $workspaceId, string $projectId, string $taskId): ?TaskAggregateState
    {
        /** @var ClientTask|null $task */
        $task = ClientTask::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $projectId)
            ->where('id', $taskId)
            ->first();

        if (! $task instanceof ClientTask) {
            return null;
        }

        return new TaskAggregateState(
            id: (string) $task->id,
            workspaceId: (string) $task->workspace_id,
            projectId: (string) $task->project_id,
            status: TaskStatus::from((string) $task->status),
            priority: TaskPriority::from((string) $task->priority),
            archived: (bool) $task->archived,
            version: (int) $task->version,
            completedAt: $task->completed_at,
            title: (string) $task->title,
            description: (string) ($task->description ?? ''),
            actorId: (string) $task->actor_id,
            actorEmail: (string) $task->actor_email,
            actorRole: TaskActorRole::from((string) $task->actor_role),
        );
    }

    public function add(TaskAggregateState $task): void
    {
        ClientTask::query()->create([
            'id' => $task->id,
            'project_id' => $task->projectId,
            'workspace_id' => $task->workspaceId,
            'title' => $task->title,
            'description' => $task->description,
            'actor_id' => $task->actorId,
            'actor_email' => $task->actorEmail,
            'actor_role' => $task->actorRole->value,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'completed_at' => $task->completedAt,
            'archived' => $task->archived,
            'version' => $task->version,
        ]);
    }

    public function save(TaskAggregateState $task, int $expectedVersion): void
    {
        $updated = ClientTask::query()
            ->where('id', $task->id)
            ->where('workspace_id', $task->workspaceId)
            ->where('project_id', $task->projectId)
            ->where('version', $expectedVersion)
            ->update([
                'status' => $task->status->value,
                'completed_at' => $task->completedAt,
                'archived' => $task->archived,
                'version' => $task->version,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $currentVersion = ClientTask::query()->where('id', $task->id)->value('version');

            throw new StaleWriteException(
                aggregateId: $task->id,
                expectedVersion: $expectedVersion,
                currentVersion: is_numeric($currentVersion) ? (int) $currentVersion : null,
            );
        }
    }
}