<?php

namespace App\Domain\ClientPortal\Write\Commands;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;

final class CreateProjectCommand
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $name,
        public readonly string $description,
        public readonly ProjectVisibility $visibility,
        public readonly ProjectStatus $initialStatus,
        public readonly WriteCommandMetadata $metadata,
    ) {
    }
}