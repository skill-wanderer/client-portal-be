<?php

namespace App\Domain\ClientPortal\Write\Commands;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use DateTimeImmutable;

final class CreateTaskCommand
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $title,
        public readonly string $description,
        public readonly TaskPriority $priority,
        public readonly string $assigneeId,
        public readonly string $assigneeEmail,
        public readonly TaskActorRole $actorRole,
        public readonly ?DateTimeImmutable $dueAt,
        public readonly WriteCommandMetadata $metadata,
    ) {
    }
}