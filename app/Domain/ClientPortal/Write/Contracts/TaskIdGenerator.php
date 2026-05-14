<?php

namespace App\Domain\ClientPortal\Write\Contracts;

interface TaskIdGenerator
{
    public function generate(string $workspaceId, string $projectId, string $idempotencyKey): string;
}