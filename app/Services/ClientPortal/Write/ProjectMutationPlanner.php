<?php

namespace App\Services\ClientPortal\Write;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Write\Commands\CreateProjectCommand;
use App\Domain\ClientPortal\Write\Commands\UpdateProjectCommand;
use App\Domain\ClientPortal\Write\Models\MutationEvent;
use App\Domain\ClientPortal\Write\Models\MutationPlan;
use App\Domain\ClientPortal\Write\Models\ProjectAggregateState;
use App\Domain\ClientPortal\Write\Models\WorkspaceMutationContext;
use App\Domain\ClientPortal\Write\Models\WriteTransactionBoundary;
use App\Domain\ClientPortal\Write\Policies\ProjectLifecycleTransitionPolicy;
use App\Domain\ClientPortal\Write\Policies\WorkspaceWriteAccessPolicy;
use App\Domain\ClientPortal\Write\Support\MutationDecision;
use App\Domain\ClientPortal\Write\Support\MutationViolation;
use App\Domain\ClientPortal\Write\Validation\CreateProjectCommandValidator;
use App\Domain\ClientPortal\Write\Validation\UpdateProjectCommandValidator;

final class ProjectMutationPlanner
{
    private readonly CreateProjectCommandValidator $createValidator;

    private readonly UpdateProjectCommandValidator $updateValidator;

    private readonly WorkspaceWriteAccessPolicy $accessPolicy;

    private readonly ProjectLifecycleTransitionPolicy $lifecyclePolicy;

    public function __construct(
        ?CreateProjectCommandValidator $createValidator = null,
        ?UpdateProjectCommandValidator $updateValidator = null,
        ?WorkspaceWriteAccessPolicy $accessPolicy = null,
        ?ProjectLifecycleTransitionPolicy $lifecyclePolicy = null,
    ) {
        $this->createValidator = $createValidator ?? new CreateProjectCommandValidator();
        $this->updateValidator = $updateValidator ?? new UpdateProjectCommandValidator();
        $this->accessPolicy = $accessPolicy ?? new WorkspaceWriteAccessPolicy();
        $this->lifecyclePolicy = $lifecyclePolicy ?? new ProjectLifecycleTransitionPolicy();
    }

    public function planCreate(WorkspaceMutationContext $workspace, CreateProjectCommand $command): MutationDecision
    {
        $violations = $this->createValidator->validate($command);

        if (! $this->accessPolicy->canCreateProject($workspace)) {
            $violations[] = new MutationViolation(
                'project_write_forbidden',
                'The current workspace role may not create projects.'
            );
        }

        if ($violations !== []) {
            return MutationDecision::reject($violations);
        }

        return MutationDecision::accept(new MutationPlan(
            operation: 'project.create',
            aggregateRootId: $command->projectId,
            workspaceId: $workspace->workspaceId,
            transactionBoundary: WriteTransactionBoundary::ProjectCreate,
            idempotencyKey: $command->metadata->idempotencyKey,
            expectedVersion: null,
            counterDeltas: [],
            events: [
                MutationEvent::projectCreated(
                    projectId: $command->projectId,
                    workspaceId: $workspace->workspaceId,
                    correlationId: $command->metadata->correlationId,
                    payload: [
                        'status' => $command->initialStatus->value,
                        'visibility' => $command->visibility->value,
                    ],
                ),
            ],
        ));
    }

    public function planUpdate(
        WorkspaceMutationContext $workspace,
        ProjectAggregateState $project,
        UpdateProjectCommand $command,
    ): MutationDecision {
        $violations = $this->updateValidator->validate($command);

        if ($project->workspaceId !== $workspace->workspaceId) {
            $violations[] = new MutationViolation(
                'cross_workspace_write_forbidden',
                'Projects may only be mutated inside the owned workspace boundary.'
            );
        }

        if (! $this->accessPolicy->canUpdateProject($workspace)) {
            $violations[] = new MutationViolation(
                'project_write_forbidden',
                'The current workspace role may not update projects.'
            );
        }

        if ($project->archived || $project->status === ProjectStatus::Archived) {
            $violations[] = new MutationViolation(
                'archived_project_mutation_forbidden',
                'Archived projects may not accept additional mutations.'
            );
        }

        if ($command->targetStatus !== null && ! $this->lifecyclePolicy->canTransition($project->status, $command->targetStatus)) {
            $violations[] = new MutationViolation(
                'illegal_project_status_transition',
                'The requested project status transition is not allowed.',
                'targetStatus'
            );
        }

        if ($violations !== []) {
            return MutationDecision::reject($violations);
        }

        $events = [];
        $boundary = WriteTransactionBoundary::ProjectUpdate;

        if ($command->targetStatus === ProjectStatus::Archived) {
            $boundary = WriteTransactionBoundary::ProjectArchive;
            $events[] = MutationEvent::projectArchived(
                projectId: $project->id,
                workspaceId: $workspace->workspaceId,
                correlationId: $command->metadata->correlationId,
                payload: ['previous_status' => $project->status->value],
            );
        }

        return MutationDecision::accept(new MutationPlan(
            operation: $boundary === WriteTransactionBoundary::ProjectArchive ? 'project.archive' : 'project.update',
            aggregateRootId: $project->id,
            workspaceId: $workspace->workspaceId,
            transactionBoundary: $boundary,
            idempotencyKey: $command->metadata->idempotencyKey,
            expectedVersion: $command->metadata->expectedVersion,
            counterDeltas: [],
            events: $events,
        ));
    }
}