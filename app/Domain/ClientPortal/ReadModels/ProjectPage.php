<?php

namespace App\Domain\ClientPortal\ReadModels;

final class ProjectPage
{
    /**
     * @param array<int, ProjectReadModel> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {
    }
}