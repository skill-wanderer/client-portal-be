<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\TaskAggregateState;

interface TaskWriteRepository
{
    public function nextIdentity(): string;

    public function findOwnedTask(string $workspaceId, string $projectId, string $taskId): ?TaskAggregateState;

    public function add(TaskAggregateState $task): void;

    public function save(TaskAggregateState $task, int $expectedVersion): void;
}