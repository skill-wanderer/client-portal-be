<?php

namespace App\Domain\ClientPortal\Write\Models;

final class MutationEvent
{
    /**
     * @param array<string, scalar|null> $payload
     */
    private function __construct(
        public readonly string $name,
        public readonly string $aggregateId,
        public readonly string $workspaceId,
        public readonly string $correlationId,
        public readonly ?string $mutationId = null,
        public readonly ?string $replayGroupId = null,
        public readonly array $payload = [],
    ) {
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function projectCreated(
        string $projectId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('ProjectCreated', $projectId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function projectArchived(
        string $projectId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('ProjectArchived', $projectId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function projectCompleted(
        string $projectId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('ProjectCompleted', $projectId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function projectReopened(
        string $projectId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('ProjectReopened', $projectId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function taskCreated(
        string $taskId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('TaskCreated', $taskId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function taskCompleted(
        string $taskId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('TaskCompleted', $taskId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    public static function taskReopened(
        string $taskId,
        string $workspaceId,
        string $correlationId,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
        array $payload = [],
    ): self {
        return new self('TaskReopened', $taskId, $workspaceId, $correlationId, $mutationId, $replayGroupId, $payload);
    }
}