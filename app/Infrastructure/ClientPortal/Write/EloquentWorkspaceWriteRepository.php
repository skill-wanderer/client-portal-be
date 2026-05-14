<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Write\Contracts\WorkspaceWriteRepository;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;
use App\Models\ClientWorkspace;

final class EloquentWorkspaceWriteRepository implements WorkspaceWriteRepository
{
    public function findOwnedWorkspace(string $actorId): ?WorkspaceMutationContext
    {
        /** @var ClientWorkspace|null $workspace */
        $workspace = ClientWorkspace::query()
            ->where('owner_sub', $actorId)
            ->first();

        if (! $workspace instanceof ClientWorkspace) {
            return null;
        }

        return new WorkspaceMutationContext(
            workspaceId: (string) $workspace->id,
            ownershipRole: OwnershipRole::from((string) $workspace->ownership_role),
            actorId: (string) $workspace->owner_sub,
            actorEmail: (string) $workspace->owner_email,
        );
    }
}