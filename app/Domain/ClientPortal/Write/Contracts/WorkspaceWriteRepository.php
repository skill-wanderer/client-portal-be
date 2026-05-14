<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;

interface WorkspaceWriteRepository
{
    public function findOwnedWorkspace(string $actorId): ?WorkspaceMutationContext;
}