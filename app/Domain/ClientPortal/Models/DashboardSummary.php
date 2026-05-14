<?php

namespace App\Domain\ClientPortal\Models;

final class DashboardSummary
{
    public function __construct(
        public readonly int $activeProjects,
        public readonly int $pendingActions,
        public readonly int $unreadMessages,
        public readonly int $recentFiles,
    ) {
    }
}