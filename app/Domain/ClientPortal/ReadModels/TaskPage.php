<?php

namespace App\Domain\ClientPortal\ReadModels;

final class TaskPage
{
    /**
     * @param array<int, TaskReadModel> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {
    }
}