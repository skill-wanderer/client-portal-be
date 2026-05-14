<?php

namespace App\Domain\ClientPortal\Models;

final class TaskSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly TaskActor $actor,
        public readonly TaskLifecycle $lifecycle,
    ) {
    }
}