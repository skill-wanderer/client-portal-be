<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\OwnershipRole;

final class WorkspaceMutationContext
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly OwnershipRole $ownershipRole,
        public readonly string $actorId,
        public readonly string $actorEmail,
    ) {
    }
}