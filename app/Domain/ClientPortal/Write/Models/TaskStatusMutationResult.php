<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\TaskStatus;
use DateTimeImmutable;

final class TaskStatusMutationResult
{
    public function __construct(
        public readonly string $projectId,
        public readonly ProjectStatus $projectStatus,
        public readonly int $projectTaskCount,
        public readonly int $projectActiveTaskCount,
        public readonly int $projectCompletedTaskCount,
        public readonly int $projectVersion,
        public readonly string $taskId,
        public readonly TaskStatus $previousStatus,
        public readonly TaskStatus $status,
        public readonly int $taskVersion,
        public readonly ?DateTimeImmutable $completedAt,
        public readonly ?ProjectWorkflowTransition $workflowTransition = null,
        /** @var array<int, string> */
        public readonly array $eventSequence = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'projectId' => $this->projectId,
            'projectStatus' => $this->projectStatus->value,
            'projectTaskCount' => $this->projectTaskCount,
            'projectActiveTaskCount' => $this->projectActiveTaskCount,
            'projectCompletedTaskCount' => $this->projectCompletedTaskCount,
            'projectVersion' => $this->projectVersion,
            'taskId' => $this->taskId,
            'previousStatus' => $this->previousStatus->value,
            'status' => $this->status->value,
            'taskVersion' => $this->taskVersion,
            'completedAt' => $this->completedAt?->format(DATE_ATOM),
            'workflowTransition' => $this->workflowTransition?->toArray(),
            'eventSequence' => $this->eventSequence,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            projectId: (string) $payload['projectId'],
            projectStatus: ProjectStatus::from((string) $payload['projectStatus']),
            projectTaskCount: (int) $payload['projectTaskCount'],
            projectActiveTaskCount: (int) $payload['projectActiveTaskCount'],
            projectCompletedTaskCount: (int) $payload['projectCompletedTaskCount'],
            projectVersion: (int) $payload['projectVersion'],
            taskId: (string) $payload['taskId'],
            previousStatus: TaskStatus::from((string) $payload['previousStatus']),
            status: TaskStatus::from((string) $payload['status']),
            taskVersion: (int) $payload['taskVersion'],
            completedAt: is_string($payload['completedAt'] ?? null)
                ? new DateTimeImmutable((string) $payload['completedAt'])
                : null,
            workflowTransition: is_array($payload['workflowTransition'] ?? null)
                ? ProjectWorkflowTransition::fromArray($payload['workflowTransition'])
                : null,
            eventSequence: array_values(array_map('strval', $payload['eventSequence'] ?? [])),
        );
    }
}