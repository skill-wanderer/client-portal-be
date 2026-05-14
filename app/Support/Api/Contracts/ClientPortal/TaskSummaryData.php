<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\TaskSummary;
use App\Support\Api\Contracts\ApiDataContract;

final class TaskSummaryData implements ApiDataContract
{
    public function __construct(
        private readonly TaskSummary $task,
    ) {
    }

    public static function fromDomain(TaskSummary $task): self
    {
        return new self($task);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->task->id,
            'title' => $this->task->title,
            'actor' => TaskActorData::fromDomain($this->task->actor)->toArray(),
            'lifecycle' => TaskLifecycleData::fromDomain($this->task->lifecycle)->toArray(),
        ];
    }
}