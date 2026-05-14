<?php

namespace App\Domain\ClientPortal\Models;

use App\Domain\ClientPortal\Enums\FileVisibility;
use DateTimeImmutable;

final class FileSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly FileVisibility $visibility,
        public readonly ?int $sizeBytes = null,
        public readonly ?DateTimeImmutable $uploadedAt = null,
    ) {
    }
}