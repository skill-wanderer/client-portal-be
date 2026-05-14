<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\TaskActorRole;

final class TaskActor
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly TaskActorRole $role,
    ) {
    }
}