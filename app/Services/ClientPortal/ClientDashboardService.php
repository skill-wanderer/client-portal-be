<?php

namespace App\Services\ClientPortal;

use App\Domain\ClientPortal\Enums\DashboardStatus;
use App\Domain\ClientPortal\Models\ClientActor;
use App\Domain\ClientPortal\Models\DashboardProjection;
use App\Domain\ClientPortal\Models\DashboardSummary;
use App\Security\Keycloak\KeycloakPrincipal;
use App\Services\Session\SessionData;

class ClientDashboardService
{
    public function build(SessionData|KeycloakPrincipal $identity): DashboardProjection
    {
        return new DashboardProjection(
            user: new ClientActor(
                id: $this->userId($identity),
                email: $this->userEmail($identity),
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

    private function userId(SessionData|KeycloakPrincipal $identity): string
    {
        return $identity instanceof SessionData ? $identity->userSub : $identity->sub;
    }

    private function userEmail(SessionData|KeycloakPrincipal $identity): string
    {
        if ($identity instanceof SessionData) {
            return $identity->userEmail;
        }

        $email = $identity->email ?? $identity->preferredUsername ?? $identity->sub;
        $normalizedEmail = strtolower(trim($email));

        return $normalizedEmail !== '' ? $normalizedEmail : $identity->sub;
    }
}
