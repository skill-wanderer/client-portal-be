<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use DateTimeImmutable;

final class ProjectDetailProjection
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly ProjectStatus $status,
        public readonly ProjectVisibility $visibility,
        public readonly bool $archived,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ProjectOwner $owner,
        public readonly WorkspaceContext $workspace,
        public readonly ProjectStats $stats,
    ) {
    }
}