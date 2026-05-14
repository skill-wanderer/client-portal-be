<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class ProjectWorkflowCoordination
{
    /**
     * @param array<int, MutationEvent> $events
     * @param array<int, MutationViolation> $violations
     */
    private function __construct(
        public readonly ?ProjectAggregateState $project,
        public readonly ?ProjectWorkflowTransition $transition,
        public readonly array $events,
        public readonly array $violations,
    ) {
    }

    /**
     * @param array<int, MutationEvent> $events
     */
    public static function accept(
        ProjectAggregateState $project,
        ?ProjectWorkflowTransition $transition = null,
        array $events = [],
    ): self {
        return new self($project, $transition, $events, []);
    }

    /**
     * @param array<int, MutationViolation> $violations
     */
    public static function reject(array $violations): self
    {
        return new self(null, null, [], $violations);
    }

    public function accepted(): bool
    {
        return $this->project instanceof ProjectAggregateState;
    }
}