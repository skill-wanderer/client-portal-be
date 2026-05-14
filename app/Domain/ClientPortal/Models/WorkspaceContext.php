<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\WorkspaceStatus;

final class WorkspaceContext
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly WorkspaceStatus $status,
        public readonly ?OwnershipRole $ownershipRole = null,
    ) {
    }
}