<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use DateTimeImmutable;

final class TaskCreationResult
{
    public function __construct(
        public readonly string $projectId,
        public readonly int $projectTaskCount,
        public readonly int $projectVersion,
        public readonly string $taskId,
        public readonly string $title,
        public readonly string $description,
        public readonly TaskPriority $priority,
        public readonly TaskStatus $status,
        public readonly bool $archived,
        public readonly int $taskVersion,
        public readonly ?DateTimeImmutable $completedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'projectId' => $this->projectId,
            'projectTaskCount' => $this->projectTaskCount,
            'projectVersion' => $this->projectVersion,
            'taskId' => $this->taskId,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'archived' => $this->archived,
            'taskVersion' => $this->taskVersion,
            'completedAt' => $this->completedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            projectId: (string) $payload['projectId'],
            projectTaskCount: (int) $payload['projectTaskCount'],
            projectVersion: (int) $payload['projectVersion'],
            taskId: (string) $payload['taskId'],
            title: (string) $payload['title'],
            description: (string) $payload['description'],
            priority: TaskPriority::from((string) $payload['priority']),
            status: TaskStatus::from((string) $payload['status']),
            archived: (bool) $payload['archived'],
            taskVersion: (int) $payload['taskVersion'],
            completedAt: is_string($payload['completedAt'] ?? null)
                ? new DateTimeImmutable((string) $payload['completedAt'])
                : null,
        );
    }
}