<?php

namespace App\Services\ClientPortal;

use App\Domain\ClientPortal\Enums\DashboardStatus;
use App\Domain\ClientPortal\Models\ClientActor;
use App\Domain\ClientPortal\Models\DashboardProjection;
use App\Domain\ClientPortal\Models\DashboardSummary;
use App\Services\Session\SessionData;

class ClientDashboardService
{
    public function build(SessionData $session): DashboardProjection
    {
        return new DashboardProjection(
            user: new ClientActor(
                id: $session->userSub,
                email: $session->userEmail,
            ),
            status: DashboardStatus::Ready,
            summary: new DashboardSummary(
                activeProjects: 0,
                pendingActions: 0,
                unreadMessages: 0,
                recentFiles: 0,
            ),
            projects: [],
            tasks: [],
            files: [],
        );
    }
}