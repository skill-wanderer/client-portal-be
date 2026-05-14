<?php

namespace Tests\Unit\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\TaskAggregateState;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use App\Services\ClientPortal\Write\ProjectWorkflowCoordinator;
use Tests\TestCase;

class ProjectWorkflowCoordinatorTest extends TestCase
{
    public function test_final_active_task_completion_transitions_project_to_completed(): void
    {
        $coordinator = new ProjectWorkflowCoordinator();
        $projectBefore = $this->project(ProjectStatus::Active, activeTaskCount: 7, completedTaskCount: 6, version: 5);
        $projectAfterCounters = $this->project(ProjectStatus::Active, activeTaskCount: 7, completedTaskCount: 7, version: 6);
        $taskBefore = $this->task(TaskStatus::InProgress);
        $taskAfter = $this->task(TaskStatus::Done, version: 2);

        $coordination = $coordinator->coordinateTaskStatusMutation(
            projectBeforeMutation: $projectBefore,
            projectAfterCounters: $projectAfterCounters,
            taskBeforeMutation: $taskBefore,
            taskAfterMutation: $taskAfter,
            metadata: $this->metadata(),
        );

        $this->assertTrue($coordination->accepted());
        $this->assertSame('completed', $coordination->project?->status->value);
        $this->assertSame('all_active_tasks_completed', $coordination->transition?->reason);
        $this->assertSame('ProjectCompleted', $coordination->events[0]->name);
    }

    public function test_done_task_reopen_transitions_completed_project_back_to_active(): void
    {
        $coordinator = new ProjectWorkflowCoordinator();
        $projectBefore = $this->project(ProjectStatus::Completed, activeTaskCount: 7, completedTaskCount: 7, version: 5);
        $projectAfterCounters = $this->project(ProjectStatus::Completed, activeTaskCount: 7, completedTaskCount: 6, version: 6);
        $taskBefore = $this->task(TaskStatus::Done);
        $taskAfter = $this->task(TaskStatus::Todo, version: 2);

        $coordination = $coordinator->coordinateTaskStatusMutation(
            projectBeforeMutation: $projectBefore,
            projectAfterCounters: $projectAfterCounters,
            taskBeforeMutation: $taskBefore,
            taskAfterMutation: $taskAfter,
            metadata: $this->metadata(),
        );

        $this->assertTrue($coordination->accepted());
        $this->assertSame('active', $coordination->project?->status->value);
        $this->assertSame('active_task_reopened', $coordination->transition?->reason);
        $this->assertSame('ProjectReopened', $coordination->events[0]->name);
    }

    public function test_invalid_counter_state_is_rejected_deterministically(): void
    {
        $coordinator = new ProjectWorkflowCoordinator();
        $projectBefore = $this->project(ProjectStatus::Active, activeTaskCount: 4, completedTaskCount: 3, version: 5);
        $projectAfterCounters = $this->project(ProjectStatus::Active, activeTaskCount: 4, completedTaskCount: 5, version: 6);

        $coordination = $coordinator->coordinateTaskStatusMutation(
            projectBeforeMutation: $projectBefore,
            projectAfterCounters: $projectAfterCounters,
            taskBeforeMutation: $this->task(TaskStatus::InProgress),
            taskAfterMutation: $this->task(TaskStatus::Done, version: 2),
            metadata: $this->metadata(),
        );

        $this->assertFalse($coordination->accepted());
        $this->assertSame('invalid_completed_task_count', $coordination->violations[0]->code);
    }

    private function project(
        ProjectStatus $status,
        int $activeTaskCount,
        int $completedTaskCount,
        int $version,
    ): ProjectAggregateState {
        return new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: $status,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: $version,
            taskCount: 8,
            activeTaskCount: $activeTaskCount,
            completedTaskCount: $completedTaskCount,
            pendingActionCount: 0,
            fileCount: 0,
            name: 'Project 1',
            description: 'Project 1 description.',
        );
    }

    private function task(TaskStatus $status, int $version = 1): TaskAggregateState
    {
        return new TaskAggregateState(
            id: 'task-1',
            workspaceId: 'workspace-owned',
            projectId: 'project-1',
            status: $status,
            priority: TaskPriority::High,
            archived: false,
            version: $version,
        );
    }

    private function metadata(): WriteCommandMetadata
    {
        return new WriteCommandMetadata(
            actorId: 'user-123',
            actorEmail: 'test@reltroner.com',
            correlationId: 'corr-project-workflow',
            idempotencyKey: 'idem-project-workflow',
            expectedVersion: 1,
        );
    }
}