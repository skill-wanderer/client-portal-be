<?php

namespace App\Domain\ClientPortal\Models;

final class ClientActor
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
    ) {
    }
}