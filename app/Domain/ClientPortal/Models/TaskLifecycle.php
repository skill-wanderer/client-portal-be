<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use DateTimeImmutable;

final class TaskLifecycle
{
    public function __construct(
        public readonly TaskStatus $status,
        public readonly TaskPriority $priority,
        public readonly ?DateTimeImmutable $dueAt = null,
        public readonly ?DateTimeImmutable $completedAt = null,
        public readonly bool $archived = false,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}