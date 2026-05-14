<?php

namespace App\Infrastructure\ClientPortal\Repositories;

use App\Domain\ClientPortal\Enums\OwnershipRole;
use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Domain\ClientPortal\Enums\ProjectVisibility;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\Enums\WorkspaceStatus;
use App\Domain\ClientPortal\ReadModels\ProjectPage;
use App\Domain\ClientPortal\ReadModels\ProjectReadModel;
use App\Domain\ClientPortal\Repositories\ProjectReadRepository;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Support\Api\Query\ListQuery;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class EloquentProjectReadRepository implements ProjectReadRepository
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function paginateByWorkspace(
        string $workspaceId,
        ListQuery $query,
        ?string $correlationId = null,
    ): ProjectPage {
        $startedAt = microtime(true);
        $builder = $this->baseQuery()->where('client_projects.workspace_id', $workspaceId);

        $this->applyFilters($builder, $query);

        $total = (clone $builder)->count('client_projects.id');

        $this->applySorting($builder, $query);

        /** @var Collection<int, ClientProject> $rows */
        $rows = $builder
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logger->info('repository.query', [
            'correlation_id' => $correlationId,
            'scope' => 'projects.list',
            'workspace_id' => $workspaceId,
            'pagination_inputs' => $query->toArray(),
            'result_total' => $total,
            'elapsed_ms' => $elapsedMs,
        ]);

        $items = array_map(
            fn (ClientProject $row): ProjectReadModel => $this->hydrateProject($row),
            $rows->all(),
        );

        $this->logger->info('repository.hydration', [
            'correlation_id' => $correlationId,
            'scope' => 'projects.list',
            'workspace_id' => $workspaceId,
            'hydrated_count' => count($items),
        ]);

        return new ProjectPage(items: $items, total: $total);
    }

    public function findById(string $projectId, ?string $correlationId = null): ?ProjectReadModel
    {
        $startedAt = microtime(true);

        /** @var ClientProject|null $row */
        $row = $this->baseQuery()
            ->where('client_projects.id', $projectId)
            ->first();

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logger->info('repository.query', [
            'correlation_id' => $correlationId,
            'scope' => 'projects.detail',
            'aggregate_id' => $projectId,
            'elapsed_ms' => $elapsedMs,
        ]);

        if (! $row instanceof ClientProject) {
            $this->logger->warning('repository.not_found', [
                'correlation_id' => $correlationId,
                'scope' => 'projects.detail',
                'aggregate_id' => $projectId,
            ]);

            return null;
        }

        $project = $this->hydrateProject($row);

        $this->logger->info('repository.hydration', [
            'correlation_id' => $correlationId,
            'scope' => 'projects.detail',
            'aggregate_id' => $projectId,
            'workspace_id' => $project->workspaceId,
            'hydrated_count' => 1,
        ]);

        return $project;
    }

    private function baseQuery(): Builder
    {
        return ClientProject::query()
            ->select('client_projects.*')
            ->join('client_workspaces', 'client_workspaces.id', '=', 'client_projects.workspace_id')
            ->addSelect([
                'client_workspaces.name as workspace_name',
                'client_workspaces.status as workspace_status',
                'client_workspaces.ownership_role as workspace_ownership_role',
                'client_workspaces.owner_sub as owner_id',
                'client_workspaces.owner_email as owner_email',
                'client_workspaces.ownership_role as owner_role',
            ])
            ->selectSub(
                ClientTask::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('client_tasks.project_id', 'client_projects.id'),
                'task_count',
            )
            ->selectSub(
                ClientTask::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('client_tasks.project_id', 'client_projects.id')
                    ->where('client_tasks.archived', false)
                    ->where('client_tasks.status', TaskStatus::Done->value),
                'completed_task_count',
            );
    }

    private function applyFilters(Builder $builder, ListQuery $query): void
    {
        if ($query->search !== null) {
            $builder->whereRaw('LOWER(client_projects.name) LIKE ?', ['%'.mb_strtolower($query->search).'%']);
        }

        if ($query->status !== null) {
            $builder->where('client_projects.status', $query->status);
        }
    }

    private function applySorting(Builder $builder, ListQuery $query): void
    {
        $direction = $query->direction;

        match ($query->sort) {
            'createdAt' => $builder->orderBy('client_projects.created_at', $direction),
            'name' => $builder->orderByRaw('LOWER(client_projects.name) '.$direction),
            default => $builder->orderBy('client_projects.updated_at', $direction),
        };

        $builder->orderBy('client_projects.id');
    }

    private function hydrateProject(ClientProject $row): ProjectReadModel
    {
        return new ProjectReadModel(
            id: (string) $row->getAttribute('id'),
            workspaceId: (string) $row->getAttribute('workspace_id'),
            workspaceName: (string) $row->getAttribute('workspace_name'),
            workspaceStatus: WorkspaceStatus::from((string) $row->getAttribute('workspace_status')),
            workspaceOwnershipRole: OwnershipRole::from((string) $row->getAttribute('workspace_ownership_role')),
            name: (string) $row->getAttribute('name'),
            description: (string) $row->getAttribute('description'),
            status: ProjectStatus::from((string) $row->getAttribute('status')),
            visibility: ProjectVisibility::from((string) $row->getAttribute('visibility')),
            archived: (bool) $row->getAttribute('archived'),
            ownerId: (string) $row->getAttribute('owner_id'),
            ownerEmail: (string) $row->getAttribute('owner_email'),
            ownerRole: OwnershipRole::from((string) $row->getAttribute('owner_role')),
            taskCount: (int) $row->getAttribute('task_count'),
            completedTaskCount: (int) $row->getAttribute('completed_task_count'),
            fileCount: (int) $row->getAttribute('file_count'),
            pendingActionCount: (int) $row->getAttribute('pending_action_count'),
            createdAt: DateTimeImmutable::createFromInterface($row->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($row->updated_at),
        );
    }
}