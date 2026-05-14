<?php

namespace App\Domain\ClientPortal\Write\Policies;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;

final class WorkspaceWriteAccessPolicy
{
    public function canCreateProject(WorkspaceMutationContext $workspace): bool
    {
        return $workspace->ownershipRole === OwnershipRole::Owner;
    }

    public function canUpdateProject(WorkspaceMutationContext $workspace): bool
    {
        return $workspace->ownershipRole === OwnershipRole::Owner;
    }

    public function canCreateTask(WorkspaceMutationContext $workspace): bool
    {
        return in_array($workspace->ownershipRole, [OwnershipRole::Owner, OwnershipRole::Collaborator], true);
    }

    public function canUpdateTaskStatus(WorkspaceMutationContext $workspace): bool
    {
        return in_array($workspace->ownershipRole, [OwnershipRole::Owner, OwnershipRole::Collaborator], true);
    }
}