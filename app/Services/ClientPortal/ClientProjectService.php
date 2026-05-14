<?php

namespace App\Services\ClientPortal;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\ProjectDetailResolution;
use App\Domain\ClientPortal\Enums\WorkspaceStatus;
use App\Domain\ClientPortal\Models\ClientActor;
use App\Domain\ClientPortal\Models\ProjectCollectionProjection;
use App\Domain\ClientPortal\Models\ProjectDetailLookup;
use App\Domain\ClientPortal\Models\ProjectDetailProjection;
use App\Domain\ClientPortal\Models\ProjectOwner;
use App\Domain\ClientPortal\Models\ProjectSummary;
use App\Domain\ClientPortal\Models\ProjectStats;
use App\Domain\ClientPortal\Models\WorkspaceContext;
use App\Domain\ClientPortal\ReadModels\ProjectReadModel;
use App\Domain\ClientPortal\Repositories\ProjectReadRepository;
use App\Services\Session\SessionData;
use App\Support\Api\Query\ListQuery;
use Psr\Log\LoggerInterface;

class ClientProjectService
{
    public function __construct(
        private readonly ProjectReadRepository $projects,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function list(
        SessionData $session,
        ListQuery $query,
        ?string $correlationId = null,
    ): ProjectCollectionProjection {
        $actor = $this->resolveActor($session);
        $workspaceId = $this->resolveWorkspaceId($actor);
        $page = $this->projects->paginateByWorkspace($workspaceId, $query, $correlationId);
        $workspace = $page->items !== []
            ? $this->buildWorkspaceContext($page->items[0])
            : $this->defaultWorkspaceContext($workspaceId);

        return new ProjectCollectionProjection(
            actor: $actor,
            workspace: $workspace,
            items: array_map(
                fn (ProjectReadModel $project): ProjectSummary => $this->buildProjectSummary($project),
                $page->items,
            ),
            total: $page->total,
        );
    }

    public function detail(
        SessionData $session,
        string $projectId,
        ?string $correlationId = null,
    ): ProjectDetailLookup {
        $actor = $this->resolveActor($session);
        $workspaceId = $this->resolveWorkspaceId($actor);
        $project = $this->projects->findById($projectId, $correlationId);

        if (! $project instanceof ProjectReadModel) {
            return new ProjectDetailLookup(
                project: null,
                resolution: ProjectDetailResolution::NotFound,
            );
        }

        if ($project->workspaceId !== $workspaceId) {
            $this->logger->warning('repository.outside_workspace', [
                'correlation_id' => $correlationId,
                'aggregate_id' => $projectId,
                'scope' => 'projects.detail',
                'workspace_id' => $workspaceId,
                'record_workspace_id' => $project->workspaceId,
            ]);

            return new ProjectDetailLookup(
                project: null,
                resolution: ProjectDetailResolution::OutsideWorkspace,
            );
        }

        return new ProjectDetailLookup(
            project: $this->buildProjectDetail($project),
            resolution: ProjectDetailResolution::Owned,
        );
    }

    private function resolveActor(SessionData $session): ClientActor
    {
        return new ClientActor(
            id: $session->userSub,
            email: $session->userEmail,
        );
    }

    private function resolveWorkspaceId(ClientActor $actor): string
    {
        return 'workspace-'.substr(sha1($actor->id), 0, 10);
    }

    private function defaultWorkspaceContext(string $workspaceId): WorkspaceContext
    {
        return new WorkspaceContext(
            id: $workspaceId,
            name: 'Client Workspace',
            status: WorkspaceStatus::Active,
            ownershipRole: OwnershipRole::Owner,
        );
    }

    private function buildWorkspaceContext(ProjectReadModel $project): WorkspaceContext
    {
        return new WorkspaceContext(
            id: $project->workspaceId,
            name: $project->workspaceName,
            status: $project->workspaceStatus,
            ownershipRole: $project->workspaceOwnershipRole,
        );
    }

    private function buildProjectSummary(ProjectReadModel $project): ProjectSummary
    {
        return new ProjectSummary(
            id: $project->id,
            name: $project->name,
            status: $project->status,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
        );
    }

    private function buildProjectDetail(ProjectReadModel $project): ProjectDetailProjection
    {
        return new ProjectDetailProjection(
            id: $project->id,
            name: $project->name,
            description: $project->description,
            status: $project->status,
            visibility: $project->visibility,
            archived: $project->archived,
            createdAt: $project->createdAt,
            updatedAt: $project->updatedAt,
            owner: new ProjectOwner(
                id: $project->ownerId,
                email: $project->ownerEmail,
                role: $project->ownerRole,
            ),
            workspace: $this->buildWorkspaceContext($project),
            stats: new ProjectStats(
                taskCount: $project->taskCount,
                completedTaskCount: $project->completedTaskCount,
                fileCount: $project->fileCount,
                pendingActionCount: $project->pendingActionCount,
            ),
        );
    }
}