<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Write\Models\TaskCreationResult;
use App\Support\Api\Contracts\ApiDataContract;

final class TaskCreationData implements ApiDataContract
{
    public function __construct(
        private readonly TaskCreationResult $result,
    ) {
    }

    public static function fromDomain(TaskCreationResult $result): self
    {
        return new self($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => [
                'id' => $this->result->projectId,
                'taskCount' => $this->result->projectTaskCount,
                'version' => $this->result->projectVersion,
            ],
            'task' => [
                'id' => $this->result->taskId,
                'title' => $this->result->title,
                'description' => $this->result->description,
                'priority' => $this->result->priority->value,
                'status' => $this->result->status->value,
                'archived' => $this->result->archived,
                'completedAt' => $this->result->completedAt?->format(DATE_ATOM),
                'version' => $this->result->taskVersion,
            ],
        ];
    }
}