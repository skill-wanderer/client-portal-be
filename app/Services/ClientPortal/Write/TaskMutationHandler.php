<?php

namespace App\Services\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Commands\UpdateTaskStatusCommand;
use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Contracts\ProjectWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\TaskWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WorkspaceWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WriteTransactionManager;
use App\Domain\ClientPortal\Write\Models\MutationPlan;
use App\Domain\ClientPortal\Write\Models\MutationEvent;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\ProjectCounterDelta;
use App\Domain\ClientPortal\Write\Models\ProjectCounterName;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\TaskStatusMutationExecution;
use App\Domain\ClientPortal\Write\Models\TaskStatusMutationResult;
use App\Domain\ClientPortal\Write\Support\StaleWriteException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class TaskMutationHandler
{
    public function __construct(
        private readonly WorkspaceWriteRepository $workspaces,
        private readonly ProjectWriteRepository $projects,
        private readonly TaskWriteRepository $tasks,
        private readonly MutationIdempotencyStore $idempotency,
        private readonly WriteTransactionManager $transactions,
        private readonly MutationEventRecorder $events,
        private readonly TaskMutationPlanner $planner,
        private readonly ProjectWorkflowCoordinator $workflowCoordinator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function updateStatus(UpdateTaskStatusCommand $command): TaskStatusMutationExecution
    {
        $workspace = $this->workspaces->findOwnedWorkspace($command->metadata->actorId);

        if ($workspace === null) {
            return TaskStatusMutationExecution::notFound();
        }

        $project = $this->projects->findOwnedProject($workspace->workspaceId, $command->projectId);

        if ($project === null) {
            return TaskStatusMutationExecution::notFound();
        }

        $task = $this->tasks->findOwnedTask($workspace->workspaceId, $command->projectId, $command->taskId);

        if ($task === null) {
            return TaskStatusMutationExecution::notFound();
        }

        $scope = $this->idempotencyScope($workspace->workspaceId, $command->projectId, $command->taskId);
        $key = (string) $command->metadata->idempotencyKey;
        $requestHash = $this->requestHash($command);
        $existingReservation = $this->idempotency->find($scope, $key);

        if ($existingReservation !== null) {
            $this->logger->info('be.mutation.idempotency.lookup', [
                'scope' => $scope,
                'aggregate_id' => $existingReservation->aggregateId,
                'status' => $existingReservation->status,
            ]);

            return $this->resolveExistingIdempotency($existingReservation, $requestHash);
        }

        if (! $this->idempotency->reserve(
            $scope,
            $key,
            $requestHash,
            $command->metadata->correlationId,
            $command->metadata->mutationId,
            $command->metadata->replayGroupId,
        )) {
            $existingReservation = $this->idempotency->find($scope, $key);

            if ($existingReservation !== null) {
                return $this->resolveExistingIdempotency($existingReservation, $requestHash);
            }

            return TaskStatusMutationExecution::conflict('IDEMPOTENCY_RESERVATION_FAILED');
        }

        $decision = $this->planner->planStatusUpdate($workspace, $project, $task, $command);

        if (! $decision->accepted() || ! $decision->plan instanceof MutationPlan) {
            $this->idempotency->release($scope, $key);

            $this->logger->warning('be.mutation.validation_failed', [
                'scope' => $scope,
                'violation_codes' => array_map(
                    static fn ($violation): string => $violation->code,
                    $decision->violations,
                ),
            ]);

            return TaskStatusMutationExecution::validationFailed($decision->violations);
        }

        if ($command->metadata->expectedVersion !== $task->version) {
            $this->idempotency->release($scope, $key);

            $this->logger->warning('be.mutation.stale_write', [
                'scope' => $scope,
                'expected_version' => $command->metadata->expectedVersion,
                'current_version' => $task->version,
            ]);

            return TaskStatusMutationExecution::conflict('STALE_WRITE', [
                'expectedVersion' => $command->metadata->expectedVersion,
                'currentVersion' => $task->version,
            ]);
        }

        $now = CarbonImmutable::now();
        $projectAfterCounters = $this->applyProjectMutation($project, $decision->plan->counterDeltas);
        $updatedTask = $this->applyTaskStatusMutation($task, $command->targetStatus, $now);
        $workflow = $this->workflowCoordinator->coordinateTaskStatusMutation(
            projectBeforeMutation: $project,
            projectAfterCounters: $projectAfterCounters,
            taskBeforeMutation: $task,
            taskAfterMutation: $updatedTask,
            metadata: $command->metadata,
        );

        if (! $workflow->accepted() || ! $workflow->project instanceof ProjectAggregateState) {
            $this->idempotency->release($scope, $key);

            return TaskStatusMutationExecution::validationFailed($workflow->violations);
        }

        $orderedEvents = array_merge($decision->plan->events, $workflow->events);

        try {
            return $this->transactions->within($decision->plan->transactionBoundary, function () use (
                $command,
                $key,
                $project,
                $scope,
                $task,
                $updatedTask,
                $workflow,
                $orderedEvents,
            ) {
                $updatedProject = $workflow->project;

                $this->tasks->save($updatedTask, $command->metadata->expectedVersion ?? $task->version);
                $this->projects->save($updatedProject, $project->version);

                foreach ($orderedEvents as $event) {
                    $this->events->record($event);
                }

                $result = new TaskStatusMutationResult(
                    projectId: $updatedProject->id,
                    projectStatus: $updatedProject->status,
                    projectTaskCount: $updatedProject->taskCount,
                    projectActiveTaskCount: $updatedProject->activeTaskCount,
                    projectCompletedTaskCount: $updatedProject->completedTaskCount,
                    projectVersion: $updatedProject->version,
                    taskId: $updatedTask->id,
                    previousStatus: $task->status,
                    status: $updatedTask->status,
                    taskVersion: $updatedTask->version,
                    completedAt: $updatedTask->completedAt,
                    workflowTransition: $workflow->transition,
                    eventSequence: array_map(
                        static fn (MutationEvent $event): string => $event->name,
                        $orderedEvents,
                    ),
                );

                $this->idempotency->complete(
                    scope: $scope,
                    key: $key,
                    aggregateId: $updatedTask->id,
                    responseStatus: 200,
                    responsePayload: $result->toArray(),
                    correlationId: $command->metadata->correlationId,
                    mutationId: $command->metadata->mutationId,
                    replayGroupId: $command->metadata->replayGroupId,
                );

                $this->logger->info('be.mutation.persist.success', [
                    'aggregate_id' => $updatedTask->id,
                    'response_status' => 200,
                ]);

                return TaskStatusMutationExecution::success($result);
            });
        } catch (StaleWriteException $exception) {
            $this->idempotency->release($scope, $key);

            $this->logger->warning('be.mutation.stale_write', [
                'scope' => $scope,
                'aggregate_id' => $exception->aggregateId,
                'expected_version' => $exception->expectedVersion,
                'current_version' => $exception->currentVersion,
            ]);

            return TaskStatusMutationExecution::conflict('STALE_WRITE', [
                'aggregateId' => $exception->aggregateId,
                'expectedVersion' => $exception->expectedVersion,
                'currentVersion' => $exception->currentVersion,
            ]);
        } catch (Throwable $exception) {
            $this->idempotency->release($scope, $key);
            $this->logger->error('tasks.status.internal_error', [
                'correlation_id' => $command->metadata->correlationId,
                'project_id' => $command->projectId,
                'task_id' => $command->taskId,
                'reason' => $exception->getMessage(),
            ]);

            return TaskStatusMutationExecution::internalError();
        }
    }

    private function resolveExistingIdempotency(
        \App\Domain\ClientPortal\Write\Models\MutationIdempotencyRecord $record,
        string $requestHash,
    ): TaskStatusMutationExecution {
        if ($record->requestHash !== $requestHash) {
            $this->logger->warning('be.mutation.idempotency.conflict', [
                'scope' => $record->scope,
                'reason' => 'IDEMPOTENCY_KEY_REUSED',
            ]);

            return TaskStatusMutationExecution::conflict('IDEMPOTENCY_KEY_REUSED');
        }

        if ($record->completed() && is_array($record->responsePayload)) {
            $this->logger->info('be.mutation.idempotency.replay', [
                'scope' => $record->scope,
                'aggregate_id' => $record->aggregateId,
                'response_status' => $record->responseStatus,
            ]);

            return TaskStatusMutationExecution::success(
                TaskStatusMutationResult::fromArray($record->responsePayload),
                replayed: true,
            );
        }

        $this->logger->warning('be.mutation.idempotency.conflict', [
            'scope' => $record->scope,
            'reason' => 'IDEMPOTENCY_IN_PROGRESS',
        ]);

        return TaskStatusMutationExecution::conflict('IDEMPOTENCY_IN_PROGRESS');
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

    private function applyTaskStatusMutation(
        TaskAggregateState $task,
        TaskStatus $targetStatus,
        DateTimeImmutable $now,
    ): TaskAggregateState {
        return new TaskAggregateState(
            id: $task->id,
            workspaceId: $task->workspaceId,
            projectId: $task->projectId,
            status: $targetStatus,
            priority: $task->priority,
            archived: $task->archived,
            version: $task->version + 1,
            completedAt: $targetStatus === TaskStatus::Done
                ? $now
                : ($targetStatus === TaskStatus::Todo ? null : $task->completedAt),
            title: $task->title,
            description: $task->description,
            actorId: $task->actorId,
            actorEmail: $task->actorEmail,
            actorRole: $task->actorRole,
        );
    }

    private function idempotencyScope(string $workspaceId, string $projectId, string $taskId): string
    {
        return 'task.status.update:'.$workspaceId.':'.$projectId.':'.$taskId;
    }

    private function requestHash(UpdateTaskStatusCommand $command): string
    {
        return hash('sha256', implode('|', [
            $command->projectId,
            $command->taskId,
            $command->targetStatus->value,
            (string) ($command->metadata->expectedVersion ?? ''),
            $command->metadata->actorId,
        ]));
    }
}