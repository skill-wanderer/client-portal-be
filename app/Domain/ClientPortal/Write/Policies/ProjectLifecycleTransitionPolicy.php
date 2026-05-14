<?php

namespace App\Domain\ClientPortal\Write\Policies;

use App\Domain\ClientPortal\Enums\ProjectStatus;

final class ProjectLifecycleTransitionPolicy
{
    public function canTransition(ProjectStatus $from, ProjectStatus $to): bool
    {
        if ($from === $to) {
            return false;
        }

        return match ($from) {
            ProjectStatus::Draft => in_array($to, [ProjectStatus::Active, ProjectStatus::Archived], true),
            ProjectStatus::Active => in_array($to, [ProjectStatus::OnHold, ProjectStatus::Completed, ProjectStatus::Archived], true),
            ProjectStatus::OnHold => in_array($to, [ProjectStatus::Active, ProjectStatus::Completed, ProjectStatus::Archived], true),
            ProjectStatus::Completed => $to === ProjectStatus::Archived,
            ProjectStatus::Archived => false,
        };
    }
}