<?php

namespace Tests\Unit\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Commands\CreateTaskCommand;
use App\Domain\ClientPortal\Write\Commands\UpdateTaskStatusCommand;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use App\Services\ClientPortal\Write\TaskMutationPlanner;
use Tests\TestCase;

class TaskMutationPlannerTest extends TestCase
{
    public function test_archived_project_cannot_accept_task_creation(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Owner);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Archived,
            visibility: ProjectVisibility::Shared,
            archived: true,
            version: 4,
        );
        $command = new CreateTaskCommand(
            projectId: 'project-1',
            title: 'New task',
            description: 'Create a new task for the archived project test.',
            priority: TaskPriority::Medium,
            assigneeId: 'user-123',
            assigneeEmail: 'test@reltroner.com',
            actorRole: TaskActorRole::Assignee,
            dueAt: null,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-create',
                idempotencyKey: 'idem-task-create',
            ),
        );

        $decision = $planner->planCreate($workspace, $project, 'task-new', $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('project_not_mutable', $decision->violations[0]->code);
    }

    public function test_task_completion_emits_counter_delta_and_event(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Collaborator);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Active,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::InProgress,
            priority: TaskPriority::High,
            archived: false,
            version: 3,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::Done,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-done',
                expectedVersion: 3,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertTrue($decision->accepted());
        $this->assertSame('task.status.update', $decision->plan?->operation);
        $this->assertCount(1, $decision->plan?->counterDeltas ?? []);
        $this->assertSame('completed_task_count', $decision->plan?->counterDeltas[0]->counter->value);
        $this->assertSame(1, $decision->plan?->counterDeltas[0]->delta);
        $this->assertSame('TaskCompleted', $decision->plan?->events[0]->name);
    }

    public function test_done_task_cannot_jump_directly_back_to_in_progress(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Owner);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Active,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::Done,
            priority: TaskPriority::High,
            archived: false,
            version: 3,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::InProgress,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-invalid-transition',
                expectedVersion: 3,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('illegal_task_status_transition', $decision->violations[0]->code);
    }

    public function test_viewer_cannot_update_task_status(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Viewer);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Active,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::Todo,
            priority: TaskPriority::Medium,
            archived: false,
            version: 1,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::InProgress,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-forbidden',
                expectedVersion: 1,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('task_write_forbidden', $decision->violations[0]->code);
    }

    public function test_reopening_a_done_task_decrements_completed_counter_and_emits_event(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Owner);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::OnHold,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::Done,
            priority: TaskPriority::High,
            archived: false,
            version: 3,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::Todo,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-reopen',
                expectedVersion: 3,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertTrue($decision->accepted());
        $this->assertSame(-1, $decision->plan?->counterDeltas[0]->delta);
        $this->assertSame('TaskReopened', $decision->plan?->events[0]->name);
    }

    public function test_completed_project_allows_deterministic_done_task_reopen(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Owner);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Completed,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
            taskCount: 8,
            activeTaskCount: 8,
            completedTaskCount: 8,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::Done,
            priority: TaskPriority::High,
            archived: false,
            version: 3,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::Todo,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-completed-project-reopen',
                expectedVersion: 3,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertTrue($decision->accepted());
        $this->assertSame(-1, $decision->plan?->counterDeltas[0]->delta);
        $this->assertSame('TaskReopened', $decision->plan?->events[0]->name);
    }

    public function test_completed_project_rejects_non_reopen_task_mutation(): void
    {
        $planner = new TaskMutationPlanner();
        $workspace = $this->workspace(OwnershipRole::Owner);
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Completed,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 5,
            taskCount: 8,
            activeTaskCount: 8,
            completedTaskCount: 8,
        );
        $task = new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: TaskStatus::Todo,
            priority: TaskPriority::Medium,
            archived: false,
            version: 1,
        );
        $command = new UpdateTaskStatusCommand(
            projectId: 'project-1',
            taskId: 'task-1',
            targetStatus: TaskStatus::InProgress,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-task-completed-project-reject',
                expectedVersion: 1,
            ),
        );

        $decision = $planner->planStatusUpdate($workspace, $project, $task, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('project_not_mutable', $decision->violations[0]->code);
    }

    private function workspace(OwnershipRole $role): WorkspaceMutationContext
    {
        return new WorkspaceMutationContext(
            workspaceId: 'workspace-owned',
            ownershipRole: $role,
            actorId: 'user-123',
            actorEmail: 'test@reltroner.com',
        );
    }
}