<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use DateTimeImmutable;

final class ProjectSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ProjectStatus $status,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}