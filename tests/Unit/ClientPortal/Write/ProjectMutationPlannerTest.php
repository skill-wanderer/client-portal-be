<?php

namespace Tests\Unit\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Write\Commands\CreateProjectCommand;
use App\Domain\ClientPortal\Write\Commands\UpdateProjectCommand;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;
use App\Domain\ClientPortal\Write\Models\WriteCommandMetadata;
use App\Services\ClientPortal\Write\ProjectMutationPlanner;
use Tests\TestCase;

class ProjectMutationPlannerTest extends TestCase
{
    public function test_create_project_returns_deterministic_validation_errors(): void
    {
        $planner = new ProjectMutationPlanner();
        $workspace = $this->ownerWorkspace('workspace-owned');
        $command = new CreateProjectCommand(
            projectId: 'project-new',
            name: ' ',
            description: 'Write foundation draft project.',
            visibility: ProjectVisibility::Private,
            initialStatus: ProjectStatus::Draft,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-project-create',
                idempotencyKey: null,
            ),
        );

        $decision = $planner->planCreate($workspace, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame(
            ['project_name_required', 'idempotency_key_required'],
            array_map(static fn ($violation) => $violation->code, $decision->violations),
        );
    }

    public function test_project_update_rejects_cross_workspace_mutations(): void
    {
        $planner = new ProjectMutationPlanner();
        $workspace = $this->ownerWorkspace('workspace-owned');
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-external',
            status: ProjectStatus::Active,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 3,
        );
        $command = new UpdateProjectCommand(
            projectId: 'project-1',
            name: 'Renamed Project',
            description: null,
            visibility: null,
            targetStatus: null,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-project-update',
                expectedVersion: 3,
            ),
        );

        $decision = $planner->planUpdate($workspace, $project, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('cross_workspace_write_forbidden', $decision->violations[0]->code);
    }

    public function test_project_archive_plan_emits_event_and_boundary(): void
    {
        $planner = new ProjectMutationPlanner();
        $workspace = $this->ownerWorkspace('workspace-owned');
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Active,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 7,
        );
        $command = new UpdateProjectCommand(
            projectId: 'project-1',
            name: null,
            description: null,
            visibility: null,
            targetStatus: ProjectStatus::Archived,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-project-archive',
                expectedVersion: 7,
            ),
        );

        $decision = $planner->planUpdate($workspace, $project, $command);

        $this->assertTrue($decision->accepted());
        $this->assertSame('project.archive', $decision->plan?->operation);
        $this->assertSame('project.archive', $decision->plan?->transactionBoundary->value);
        $this->assertCount(1, $decision->plan?->events ?? []);
        $this->assertSame('ProjectArchived', $decision->plan?->events[0]->name);
    }

    public function test_completed_project_cannot_transition_back_to_active(): void
    {
        $planner = new ProjectMutationPlanner();
        $workspace = $this->ownerWorkspace('workspace-owned');
        $project = new ProjectAggregateState(
            id: 'project-1',
            workspaceId: 'workspace-owned',
            status: ProjectStatus::Completed,
            visibility: ProjectVisibility::Shared,
            archived: false,
            version: 9,
        );
        $command = new UpdateProjectCommand(
            projectId: 'project-1',
            name: null,
            description: null,
            visibility: null,
            targetStatus: ProjectStatus::Active,
            metadata: new WriteCommandMetadata(
                actorId: 'user-123',
                actorEmail: 'test@reltroner.com',
                correlationId: 'corr-write-project-invalid-transition',
                expectedVersion: 9,
            ),
        );

        $decision = $planner->planUpdate($workspace, $project, $command);

        $this->assertFalse($decision->accepted());
        $this->assertSame('illegal_project_status_transition', $decision->violations[0]->code);
    }

    private function ownerWorkspace(string $workspaceId): WorkspaceMutationContext
    {
        return new WorkspaceMutationContext(
            workspaceId: $workspaceId,
            ownershipRole: OwnershipRole::Owner,
            actorId: 'user-123',
            actorEmail: 'test@reltroner.com',
        );
    }
}