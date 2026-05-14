<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use DateTimeImmutable;

final class TaskAggregateState
{
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly string $projectId,
        public readonly TaskStatus $status,
        public readonly TaskPriority $priority,
        public readonly bool $archived,
        public readonly int $version,
        public readonly ?DateTimeImmutable $completedAt = null,
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly string $actorId = '',
        public readonly string $actorEmail = '',
        public readonly TaskActorRole $actorRole = TaskActorRole::Assignee,
    ) {
    }
}