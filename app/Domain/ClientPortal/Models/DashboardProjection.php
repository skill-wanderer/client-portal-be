<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\DashboardStatus;

final class DashboardProjection
{
    /**
     * @param array<int, ProjectSummary> $projects
     * @param array<int, TaskSummary> $tasks
     * @param array<int, FileSummary> $files
     */
    public function __construct(
        public readonly ClientActor $user,
        public readonly DashboardStatus $status,
        public readonly DashboardSummary $summary,
        public readonly array $projects = [],
        public readonly array $tasks = [],
        public readonly array $files = [],
        public readonly ?WorkspaceContext $workspace = null,
    ) {
    }
}