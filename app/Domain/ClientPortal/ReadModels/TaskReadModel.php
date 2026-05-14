<?php

namespace App\Domain\ClientPortal\ReadModels;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use DateTimeImmutable;

final class TaskReadModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $title,
        public readonly string $actorId,
        public readonly string $actorEmail,
        public readonly TaskActorRole $actorRole,
        public readonly TaskStatus $status,
        public readonly TaskPriority $priority,
        public readonly ?DateTimeImmutable $dueAt,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly bool $archived,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}