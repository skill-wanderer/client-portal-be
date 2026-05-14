<?php

namespace App\Domain\ClientPortal\Write\Models;

final class MutationPlan
{
    /**
     * @param array<int, ProjectCounterDelta> $counterDeltas
     * @param array<int, MutationEvent> $events
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $aggregateRootId,
        public readonly string $workspaceId,
        public readonly WriteTransactionBoundary $transactionBoundary,
        public readonly ?string $idempotencyKey,
        public readonly ?int $expectedVersion,
        public readonly array $counterDeltas = [],
        public readonly array $events = [],
    ) {
    }
}