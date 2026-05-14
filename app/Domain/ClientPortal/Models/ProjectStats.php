<?php

namespace App\Domain\ClientPortal\Models;

final class ProjectStats
{
    public function __construct(
        public readonly int $taskCount,
        public readonly int $completedTaskCount,
        public readonly int $fileCount,
        public readonly int $pendingActionCount,
    ) {
    }
}