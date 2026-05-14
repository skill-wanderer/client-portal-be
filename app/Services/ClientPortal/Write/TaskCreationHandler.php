<?php

namespace App\Services\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Commands\CreateTaskCommand;
use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Contracts\ProjectWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\TaskIdGenerator;
use App\Domain\ClientPortal\Write\Contracts\TaskWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WorkspaceWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WriteTransactionManager;
use App\Domain\ClientPortal\Write\Models\MutationPlan;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\ProjectCounterDelta;
use App\Domain\ClientPortal\Write\Models\ProjectCounterName;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\TaskCreationExecution;
use App\Domain\ClientPortal\Write\Models\TaskCreationResult;
use App\Domain\ClientPortal\Write\Models\MutationIdempotencyRecord;
use App\Domain\ClientPortal\Write\Support\StaleWriteException;
use Psr\Log\LoggerInterface;
use Throwable;

final class TaskCreationHandler
{
    public function __construct(
        private readonly WorkspaceWriteRepository $workspaces,
        private readonly ProjectWriteRepository $projects,
        private readonly TaskWriteRepository $tasks,
        private readonly TaskIdGenerator $taskIds,
        private readonly MutationIdempotencyStore $idempotency,
        private readonly WriteTransactionManager $transactions,
        private readonly MutationEventRecorder $events,
        private readonly TaskMutationPlanner $planner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(CreateTaskCommand $command): TaskCreationExecution
    {
        $workspace = $this->workspaces->findOwnedWorkspace($command->metadata->actorId);

        if ($workspace === null) {
            return TaskCreationExecution::notFound();
        }

        $project = $this->projects->findOwnedProject($workspace->workspaceId, $command->projectId);

        if ($project === null) {
            return TaskCreationExecution::notFound();
        }

        $scope = $this->idempotencyScope($workspace->workspaceId, $command->projectId);
        $key = (string) $command->metadata->idempotencyKey;
        $requestHash = $this->requestHash($command);
        $existingReservation = $this->idempotency->find($scope, $key);

        if ($existingReservation !== null) {
            return $this->resolveExistingIdempotency($existingReservation, $requestHash);
        }

        if (! $this->idempotency->reserve($scope, $key, $requestHash)) {
            $existingReservation = $this->idempotency->find($scope, $key);

            if ($existingReservation !== null) {
                return $this->resolveExistingIdempotency($existingReservation, $requestHash);
            }

            return TaskCreationExecution::conflict('IDEMPOTENCY_RESERVATION_FAILED');
        }

        $taskId = $this->taskIds->generate($workspace->workspaceId, $command->projectId, $key);
        $decision = $this->planner->planCreate($workspace, $project, $taskId, $command);

        if (! $decision->accepted() || ! $decision->plan instanceof MutationPlan) {
            $this->idempotency->release($scope, $key);

            return TaskCreationExecution::validationFailed($decision->violations);
        }

        try {
            return $this->transactions->within($decision->plan->transactionBoundary, function () use (
                $command,
                $decision,
                $key,
                $project,
                $scope,
                $taskId,
            ) {
                $createdTask = $this->createTaskAggregate($project, $command, $taskId);
                $updatedProject = $this->applyProjectMutation($project, $decision->plan->counterDeltas);

                $this->tasks->add($createdTask);
                $this->projects->save($updatedProject, $project->version);

                foreach ($decision->plan->events as $event) {
                    $this->events->record($event);
                }

                $result = new TaskCreationResult(
                    projectId: $updatedProject->id,
                    projectTaskCount: $updatedProject->taskCount,
                    projectVersion: $updatedProject->version,
                    taskId: $createdTask->id,
                    title: $createdTask->title,
                    description: $createdTask->description,
                    priority: $createdTask->priority,
                    status: $createdTask->status,
                    archived: $createdTask->archived,
                    taskVersion: $createdTask->version,
                    completedAt: $createdTask->completedAt,
                );

                $this->idempotency->complete(
                    scope: $scope,
                    key: $key,
                    aggregateId: $createdTask->id,
                    responseStatus: 201,
                    responsePayload: $result->toArray(),
                );

                return TaskCreationExecution::success($result);
            });
        } catch (StaleWriteException $exception) {
            $this->idempotency->release($scope, $key);

            return TaskCreationExecution::conflict('STALE_WRITE', [
                'expectedVersion' => $exception->expectedVersion,
                'currentVersion' => $exception->currentVersion,
            ]);
        } catch (Throwable $exception) {
            $this->idempotency->release($scope, $key);
            $this->logger->error('tasks.create.internal_error', [
                'correlation_id' => $command->metadata->correlationId,
                'project_id' => $command->projectId,
                'reason' => $exception->getMessage(),
            ]);

            return TaskCreationExecution::internalError();
        }
    }

    private function resolveExistingIdempotency(
        MutationIdempotencyRecord $record,
        string $requestHash,
    ): TaskCreationExecution {
        if ($record->requestHash !== $requestHash) {
            return TaskCreationExecution::conflict('IDEMPOTENCY_KEY_REUSED');
        }

        if ($record->completed() && is_array($record->responsePayload)) {
            return TaskCreationExecution::success(
                TaskCreationResult::fromArray($record->responsePayload),
                replayed: true,
            );
        }

        return TaskCreationExecution::conflict('IDEMPOTENCY_IN_PROGRESS');
    }

    /**
     * @param array<int, ProjectCounterDelta> $counterDeltas
     */
    private function applyProjectMutation(ProjectAggregateState $project, array $counterDeltas): ProjectAggregateState
    {
        $taskCount = $project->taskCount;
        $activeTaskCount = $project->activeTaskCount;
        $completedTaskCount = $project->completedTaskCount;

        foreach ($counterDeltas as $delta) {
            if ($delta->counter === ProjectCounterName::TaskCount) {
                $taskCount += $delta->delta;
            }

            if ($delta->counter === ProjectCounterName::ActiveTaskCount) {
                $activeTaskCount += $delta->delta;
            }

            if ($delta->counter === ProjectCounterName::CompletedTaskCount) {
                $completedTaskCount += $delta->delta;
            }
        }

        return new ProjectAggregateState(
            id: $project->id,
            workspaceId: $project->workspaceId,
            status: $project->status,
            visibility: $project->visibility,
            archived: $project->archived,
            version: $project->version + 1,
            taskCount: max(0, $taskCount),
            activeTaskCount: max(0, $activeTaskCount),
            completedTaskCount: max(0, $completedTaskCount),
            pendingActionCount: $project->pendingActionCount,
            fileCount: $project->fileCount,
            name: $project->name,
            description: $project->description,
        );
    }

    private function createTaskAggregate(
        ProjectAggregateState $project,
        CreateTaskCommand $command,
        string $taskId,
    ): TaskAggregateState {
        return new TaskAggregateState(
            id: $taskId,
            workspaceId: $project->workspaceId,
            projectId: $project->id,
            status: TaskStatus::Todo,
            priority: $command->priority,
            archived: false,
            version: 1,
            completedAt: null,
            title: $command->title,
            description: $command->description,
            actorId: $command->assigneeId,
            actorEmail: $command->assigneeEmail,
            actorRole: $command->actorRole,
        );
    }

    private function idempotencyScope(string $workspaceId, string $projectId): string
    {
        return 'task.create:'.$workspaceId.':'.$projectId;
    }

    private function requestHash(CreateTaskCommand $command): string
    {
        return hash('sha256', implode('|', [
            $command->projectId,
            $command->title,
            $command->description,
            $command->priority->value,
            $command->assigneeId,
            $command->assigneeEmail,
            $command->actorRole->value,
            $command->metadata->actorId,
            $command->metadata->actorEmail,
            (string) ($command->dueAt?->format(DATE_ATOM) ?? ''),
        ]));
    }
}