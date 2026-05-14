<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\OwnershipRole;

final class ProjectOwner
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly OwnershipRole $role,
    ) {
    }
}