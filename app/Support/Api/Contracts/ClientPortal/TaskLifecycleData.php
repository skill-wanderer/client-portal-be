<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\TaskLifecycle;
use App\Support\Api\Contracts\ApiDataContract;

final class TaskLifecycleData implements ApiDataContract
{
    public function __construct(
        private readonly TaskLifecycle $lifecycle,
    ) {
    }

    public static function fromDomain(TaskLifecycle $lifecycle): self
    {
        return new self($lifecycle);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->lifecycle->status->value,
            'priority' => $this->lifecycle->priority->value,
            'dueAt' => $this->lifecycle->dueAt?->format(DATE_ATOM),
            'completedAt' => $this->lifecycle->completedAt?->format(DATE_ATOM),
            'archived' => $this->lifecycle->archived,
            'createdAt' => $this->lifecycle->createdAt?->format(DATE_ATOM),
            'updatedAt' => $this->lifecycle->updatedAt?->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null);
    }
}