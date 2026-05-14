<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;

interface ProjectWriteRepository
{
    public function nextIdentity(): string;

    public function findOwnedProject(string $workspaceId, string $projectId): ?ProjectAggregateState;

    public function add(ProjectAggregateState $project): void;

    public function save(ProjectAggregateState $project, int $expectedVersion): void;
}