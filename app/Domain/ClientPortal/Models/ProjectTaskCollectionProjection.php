<?php

namespace App\Domain\ClientPortal\Models;

final class ProjectTaskCollectionProjection
{
    /**
     * @param array<int, TaskSummary> $items
     */
    public function __construct(
        public readonly ProjectDetailProjection $project,
        public readonly array $items,
        public readonly int $total,
    ) {
    }
}