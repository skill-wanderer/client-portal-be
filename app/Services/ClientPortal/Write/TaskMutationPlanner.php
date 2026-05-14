<?php

namespace App\Services\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Commands\CreateTaskCommand;
use App\Domain\ClientPortal\Write\Commands\UpdateTaskStatusCommand;
use App\Domain\ClientPortal\Write\Models\MutationEvent;
use App\Domain\ClientPortal\Write\Models\MutationPlan;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\ProjectCounterDelta;
use App\Domain\ClientPortal\Write\Models\ProjectCounterName;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;
use App\Domain\ClientPortal\Write\Models\WriteTransactionBoundary;
use App\Domain\ClientPortal\Write\Policies\TaskLifecycleTransitionPolicy;
use App\Domain\ClientPortal\Write\Policies\WorkspaceWriteAccessPolicy;
use App\Domain\ClientPortal\Write\Support\MutationDecision;
use App\Domain\ClientPortal\Write\Support\MutationViolation;
use App\Domain\ClientPortal\Write\Validation\CreateTaskCommandValidator;
use App\Domain\ClientPortal\Write\Validation\UpdateTaskStatusCommandValidator;

final class TaskMutationPlanner
{
    private readonly CreateTaskCommandValidator $createValidator;

    private readonly UpdateTaskStatusCommandValidator $statusValidator;

    private readonly WorkspaceWriteAccessPolicy $accessPolicy;

    private readonly TaskLifecycleTransitionPolicy $lifecyclePolicy;

    public function __construct(
        ?CreateTaskCommandValidator $createValidator = null,
        ?UpdateTaskStatusCommandValidator $statusValidator = null,
        ?WorkspaceWriteAccessPolicy $accessPolicy = null,
        ?TaskLifecycleTransitionPolicy $lifecyclePolicy = null,
    ) {
        $this->createValidator = $createValidator ?? new CreateTaskCommandValidator();
        $this->statusValidator = $statusValidator ?? new UpdateTaskStatusCommandValidator();
        $this->accessPolicy = $accessPolicy ?? new WorkspaceWriteAccessPolicy();
        $this->lifecyclePolicy = $lifecyclePolicy ?? new TaskLifecycleTransitionPolicy();
    }

    public function planCreate(
        WorkspaceMutationContext $workspace,
        ProjectAggregateState $project,
        string $taskId,
        CreateTaskCommand $command,
    ): MutationDecision {
        $violations = $this->createValidator->validate($command);

        if (! $this->accessPolicy->canCreateTask($workspace)) {
            $violations[] = new MutationViolation(
                'task_write_forbidden',
                'The current workspace role may not create tasks.'
            );
        }

        $violations = array_merge($violations, $this->projectScopeViolations($workspace, $project, $command->projectId));

        if ($violations !== []) {
            return MutationDecision::reject($violations);
        }

        return MutationDecision::accept(new MutationPlan(
            operation: 'task.create',
            aggregateRootId: $taskId,
            workspaceId: $workspace->workspaceId,
            transactionBoundary: WriteTransactionBoundary::TaskCreate,
            idempotencyKey: $command->metadata->idempotencyKey,
            expectedVersion: null,
            counterDeltas: [
                new ProjectCounterDelta(ProjectCounterName::TaskCount, 1, 'task_created'),
                new ProjectCounterDelta(ProjectCounterName::ActiveTaskCount, 1, 'task_created'),
            ],
            events: [
                MutationEvent::taskCreated(
                    taskId: $taskId,
                    workspaceId: $workspace->workspaceId,
                    correlationId: $command->metadata->correlationId,
                    payload: [
                        'project_id' => $project->id,
                        'title' => $command->title,
                        'description' => $command->description,
                        'priority' => $command->priority->value,
                        'actor_role' => $command->actorRole->value,
                        'actor_id' => $command->metadata->actorId,
                        'actor_email' => $command->metadata->actorEmail,
                    ],
                ),
            ],
        ));
    }

    public function planStatusUpdate(
        WorkspaceMutationContext $workspace,
        ProjectAggregateState $project,
        TaskAggregateState $task,
        UpdateTaskStatusCommand $command,
    ): MutationDecision {
        $violations = $this->statusValidator->validate($command);

        if (! $this->accessPolicy->canUpdateTaskStatus($workspace)) {
            $violations[] = new MutationViolation(
                'task_write_forbidden',
                'The current workspace role may not update task status.'
            );
        }

        $violations = array_merge(
            $violations,
            $this->projectScopeViolations($workspace, $project, $command->projectId, $task, $command->targetStatus),
        );

        if ($task->workspaceId !== $workspace->workspaceId || $task->projectId !== $project->id) {
            $violations[] = new MutationViolation(
                'cross_workspace_write_forbidden',
                'Tasks may only be mutated inside their parent project workspace boundary.'
            );
        }

        if ($task->archived) {
            $violations[] = new MutationViolation(
                'archived_task_mutation_forbidden',
                'Archived tasks may not accept additional mutations.'
            );
        }

        if (! $this->lifecyclePolicy->canTransition($task->status, $command->targetStatus)) {
            $violations[] = new MutationViolation(
                'illegal_task_status_transition',
                'The requested task status transition is not allowed.',
                'targetStatus'
            );
        }

        if ($violations !== []) {
            return MutationDecision::reject($violations);
        }

        $counterDeltas = [];
        $events = [];

        if ($task->status !== TaskStatus::Done && $command->targetStatus === TaskStatus::Done) {
            $counterDeltas[] = new ProjectCounterDelta(ProjectCounterName::CompletedTaskCount, 1, 'task_completed');
            $events[] = MutationEvent::taskCompleted(
                taskId: $task->id,
                workspaceId: $workspace->workspaceId,
                correlationId: $command->metadata->correlationId,
                payload: [
                    'project_id' => $project->id,
                    'actor_id' => $command->metadata->actorId,
                    'actor_email' => $command->metadata->actorEmail,
                    'from_status' => $task->status->value,
                    'to_status' => $command->targetStatus->value,
                ],
            );
        }

        if ($task->status === TaskStatus::Done && $command->targetStatus === TaskStatus::Todo) {
            $counterDeltas[] = new ProjectCounterDelta(ProjectCounterName::CompletedTaskCount, -1, 'task_reopened');
            $events[] = MutationEvent::taskReopened(
                taskId: $task->id,
                workspaceId: $workspace->workspaceId,
                correlationId: $command->metadata->correlationId,
                payload: [
                    'project_id' => $project->id,
                    'actor_id' => $command->metadata->actorId,
                    'actor_email' => $command->metadata->actorEmail,
                    'from_status' => $task->status->value,
                    'to_status' => $command->targetStatus->value,
                ],
            );
        }

        return MutationDecision::accept(new MutationPlan(
            operation: 'task.status.update',
            aggregateRootId: $task->id,
            workspaceId: $workspace->workspaceId,
            transactionBoundary: WriteTransactionBoundary::TaskStatusUpdate,
            idempotencyKey: $command->metadata->idempotencyKey,
            expectedVersion: $command->metadata->expectedVersion,
            counterDeltas: $counterDeltas,
            events: $events,
        ));
    }

    /**
     * @return array<int, MutationViolation>
     */
    private function projectScopeViolations(
        WorkspaceMutationContext $workspace,
        ProjectAggregateState $project,
        string $commandProjectId,
        ?TaskAggregateState $task = null,
        ?TaskStatus $targetStatus = null,
    ): array {
        $violations = [];

        if ($project->workspaceId !== $workspace->workspaceId) {
            $violations[] = new MutationViolation(
                'cross_workspace_write_forbidden',
                'Projects may only be mutated inside the owned workspace boundary.'
            );
        }

        if ($commandProjectId !== $project->id) {
            $violations[] = new MutationViolation(
                'project_task_mismatch',
                'The target task mutation does not belong to the provided project.',
                'projectId'
            );
        }

        $allowsCompletedProjectReopen = $project->status === ProjectStatus::Completed
            && ! $project->archived
            && $task instanceof TaskAggregateState
            && $task->status === TaskStatus::Done
            && $targetStatus === TaskStatus::Todo;

        if (
            $project->archived
            || (! in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true) && ! $allowsCompletedProjectReopen)
        ) {
            $violations[] = new MutationViolation(
                'project_not_mutable',
                'Tasks may only be mutated while the parent project is active or on hold.'
            );
        }

        return $violations;
    }
}