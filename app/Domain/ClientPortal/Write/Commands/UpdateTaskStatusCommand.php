<?php

namespace App\Domain\ClientPortal\Write\Commands;

use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;

final class UpdateTaskStatusCommand
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $taskId,
        public readonly TaskStatus $targetStatus,
        public readonly WriteCommandMetadata $metadata,
    ) {
    }
}