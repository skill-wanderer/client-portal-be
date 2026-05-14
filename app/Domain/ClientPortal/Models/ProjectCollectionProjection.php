<?php

namespace App\Domain\ClientPortal\Models;

final class ProjectCollectionProjection
{
    /**
     * @param array<int, ProjectSummary> $items
     */
    public function __construct(
        public readonly ClientActor $actor,
        public readonly WorkspaceContext $workspace,
        public readonly array $items,
        public readonly int $total,
    ) {
    }
}