<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;

final class ProjectAggregateState
{
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly ProjectStatus $status,
        public readonly ProjectVisibility $visibility,
        public readonly bool $archived,
        public readonly int $version,
        public readonly int $taskCount = 0,
        public readonly int $activeTaskCount = 0,
        public readonly int $completedTaskCount = 0,
        public readonly int $pendingActionCount = 0,
        public readonly int $fileCount = 0,
        public readonly string $name = '',
        public readonly string $description = '',
    ) {
    }
}