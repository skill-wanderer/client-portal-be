<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\TaskIdGenerator;

final class DeterministicTaskIdGenerator implements TaskIdGenerator
{
    public function generate(string $workspaceId, string $projectId, string $idempotencyKey): string
    {
        return $projectId.'-task-gen-'.substr(hash('sha256', implode('|', [
            $workspaceId,
            $projectId,
            $idempotencyKey,
        ])), 0, 16);
    }
}