<?php

namespace App\Services\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Models\MutationEvent;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\ProjectWorkflowCoordination;
use App\Domain\ClientPortal\Write\Models\ProjectWorkflowTransition;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use App\Domain\ClientPortal\Write\Policies\ProjectLifecycleTransitionPolicy;
use App\Domain\ClientPortal\Write\Support\MutationViolation;

final class ProjectWorkflowCoordinator
{
    public function __construct(
        private readonly ProjectLifecycleTransitionPolicy $lifecyclePolicy = new ProjectLifecycleTransitionPolicy(),
    ) {
    }

    public function coordinateTaskStatusMutation(
        ProjectAggregateState $projectBeforeMutation,
        ProjectAggregateState $projectAfterCounters,
        TaskAggregateState $taskBeforeMutation,
        TaskAggregateState $taskAfterMutation,
        WriteCommandMetadata $metadata,
    ): ProjectWorkflowCoordination {
        $violations = $this->consistencyViolations($projectAfterCounters);

        if ($violations !== []) {
            return ProjectWorkflowCoordination::reject($violations);
        }

        if (
            $projectBeforeMutation->status === ProjectStatus::Active
            && $taskBeforeMutation->status !== TaskStatus::Done
            && $taskAfterMutation->status === TaskStatus::Done
            && $projectAfterCounters->activeTaskCount > 0
            && $projectAfterCounters->completedTaskCount === $projectAfterCounters->activeTaskCount
        ) {
            return $this->transitionProject(
                projectBeforeMutation: $projectBeforeMutation,
                projectAfterCounters: $projectAfterCounters,
                metadata: $metadata,
                toStatus: ProjectStatus::Completed,
                reason: 'all_active_tasks_completed',
                eventFactory: static fn (ProjectAggregateState $project, WriteCommandMetadata $commandMetadata): MutationEvent => MutationEvent::projectCompleted(
                    projectId: $project->id,
                    workspaceId: $project->workspaceId,
                    correlationId: $commandMetadata->correlationId,
                    payload: [
                        'actor_id' => $commandMetadata->actorId,
                        'actor_email' => $commandMetadata->actorEmail,
                        'from_status' => ProjectStatus::Active->value,
                        'to_status' => ProjectStatus::Completed->value,
                        'task_count' => $project->taskCount,
                        'active_task_count' => $project->activeTaskCount,
                        'completed_task_count' => $project->completedTaskCount,
                    ],
                ),
            );
        }

        if (
            $projectBeforeMutation->status === ProjectStatus::Completed
            && $taskBeforeMutation->status === TaskStatus::Done
            && $taskAfterMutation->status === TaskStatus::Todo
            && $projectAfterCounters->completedTaskCount < $projectAfterCounters->activeTaskCount
        ) {
            return $this->transitionProject(
                projectBeforeMutation: $projectBeforeMutation,
                projectAfterCounters: $projectAfterCounters,
                metadata: $metadata,
                toStatus: ProjectStatus::Active,
                reason: 'active_task_reopened',
                eventFactory: static fn (ProjectAggregateState $project, WriteCommandMetadata $commandMetadata): MutationEvent => MutationEvent::projectReopened(
                    projectId: $project->id,
                    workspaceId: $project->workspaceId,
                    correlationId: $commandMetadata->correlationId,
                    payload: [
                        'actor_id' => $commandMetadata->actorId,
                        'actor_email' => $commandMetadata->actorEmail,
                        'from_status' => ProjectStatus::Completed->value,
                        'to_status' => ProjectStatus::Active->value,
                        'task_count' => $project->taskCount,
                        'active_task_count' => $project->activeTaskCount,
                        'completed_task_count' => $project->completedTaskCount,
                    ],
                ),
            );
        }

        if (
            $projectBeforeMutation->status === ProjectStatus::Completed
            && $projectAfterCounters->completedTaskCount < $projectAfterCounters->activeTaskCount
        ) {
            return ProjectWorkflowCoordination::reject([
                new MutationViolation(
                    'inconsistent_project_workflow_state',
                    'Completed projects must reopen once an active task becomes incomplete.'
                ),
            ]);
        }

        return ProjectWorkflowCoordination::accept($projectAfterCounters);
    }

    /**
     * @return array<int, MutationViolation>
     */
    private function consistencyViolations(ProjectAggregateState $project): array
    {
        $violations = [];

        if ($project->activeTaskCount > $project->taskCount) {
            $violations[] = new MutationViolation(
                'invalid_active_task_count',
                'Active task count may not exceed total task count.'
            );
        }

        if ($project->completedTaskCount > $project->activeTaskCount) {
            $violations[] = new MutationViolation(
                'invalid_completed_task_count',
                'Completed task count may not exceed active task count.'
            );
        }

        return $violations;
    }

    /**
     * @param callable(ProjectAggregateState, WriteCommandMetadata): MutationEvent $eventFactory
     */
    private function transitionProject(
        ProjectAggregateState $projectBeforeMutation,
        ProjectAggregateState $projectAfterCounters,
        WriteCommandMetadata $metadata,
        ProjectStatus $toStatus,
        string $reason,
        callable $eventFactory,
    ): ProjectWorkflowCoordination {
        if (! $this->canWorkflowTransition($projectBeforeMutation->status, $toStatus)) {
            return ProjectWorkflowCoordination::reject([
                new MutationViolation(
                    'illegal_project_workflow_transition',
                    'The derived project workflow transition is not allowed.'
                ),
            ]);
        }

        $project = new ProjectAggregateState(
            id: $projectAfterCounters->id,
            workspaceId: $projectAfterCounters->workspaceId,
            status: $toStatus,
            visibility: $projectAfterCounters->visibility,
            archived: $projectAfterCounters->archived,
            version: $projectAfterCounters->version,
            taskCount: $projectAfterCounters->taskCount,
            activeTaskCount: $projectAfterCounters->activeTaskCount,
            completedTaskCount: $projectAfterCounters->completedTaskCount,
            pendingActionCount: $projectAfterCounters->pendingActionCount,
            fileCount: $projectAfterCounters->fileCount,
            name: $projectAfterCounters->name,
            description: $projectAfterCounters->description,
        );

        return ProjectWorkflowCoordination::accept(
            project: $project,
            transition: new ProjectWorkflowTransition($projectBeforeMutation->status, $toStatus, $reason),
            events: [$eventFactory($project, $metadata)],
        );
    }

    private function canWorkflowTransition(ProjectStatus $from, ProjectStatus $to): bool
    {
        if ($from === ProjectStatus::Completed && $to === ProjectStatus::Active) {
            return true;
        }

        return $this->lifecyclePolicy->canTransition($from, $to);
    }
}