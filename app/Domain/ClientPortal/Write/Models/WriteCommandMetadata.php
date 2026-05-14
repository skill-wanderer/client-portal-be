<?php

namespace App\Domain\ClientPortal\Write\Models;

final class WriteCommandMetadata
{
    public function __construct(
        public readonly string $actorId,
        public readonly string $actorEmail,
        public readonly string $correlationId,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $expectedVersion = null,
    ) {
    }
}