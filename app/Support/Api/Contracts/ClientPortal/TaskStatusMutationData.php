<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Write\Models\TaskStatusMutationResult;
use App\Support\Api\Contracts\ApiDataContract;

final class TaskStatusMutationData implements ApiDataContract
{
    public function __construct(
        private readonly TaskStatusMutationResult $result,
    ) {
    }

    public static function fromDomain(TaskStatusMutationResult $result): self
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
                'status' => $this->result->projectStatus->value,
                'taskCount' => $this->result->projectTaskCount,
                'activeTaskCount' => $this->result->projectActiveTaskCount,
                'completedTaskCount' => $this->result->projectCompletedTaskCount,
                'version' => $this->result->projectVersion,
            ],
            'task' => [
                'id' => $this->result->taskId,
                'previousStatus' => $this->result->previousStatus->value,
                'status' => $this->result->status->value,
                'completedAt' => $this->result->completedAt?->format(DATE_ATOM),
                'version' => $this->result->taskVersion,
            ],
            'transition' => [
                'from' => $this->result->previousStatus->value,
                'to' => $this->result->status->value,
            ],
            'workflow' => [
                'projectTransition' => $this->result->workflowTransition?->toArray(),
                'eventSequence' => $this->result->eventSequence,
            ],
        ];
    }
}