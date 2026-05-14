<?php

namespace App\Domain\ClientPortal\ReadModels;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Enums\WorkspaceStatus;
use DateTimeImmutable;

final class ProjectReadModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $workspaceId,
        public readonly string $workspaceName,
        public readonly WorkspaceStatus $workspaceStatus,
        public readonly OwnershipRole $workspaceOwnershipRole,
        public readonly string $name,
        public readonly string $description,
        public readonly ProjectStatus $status,
        public readonly ProjectVisibility $visibility,
        public readonly bool $archived,
        public readonly string $ownerId,
        public readonly string $ownerEmail,
        public readonly OwnershipRole $ownerRole,
        public readonly int $taskCount,
        public readonly int $completedTaskCount,
        public readonly int $fileCount,
        public readonly int $pendingActionCount,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}