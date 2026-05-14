<?php

namespace App\Infrastructure\ClientPortal\Repositories;

use App\Domain\ClientPortal\Enums\TaskActorRole;
use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Domain\ClientPortal\Enums\TaskStatus;
use App\Domain\ClientPortal\ReadModels\TaskPage;
use App\Domain\ClientPortal\ReadModels\TaskReadModel;
use App\Domain\ClientPortal\Repositories\TaskReadRepository;
use App\Models\ClientTask;
use App\Support\Api\Query\ListQuery;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class EloquentTaskReadRepository implements TaskReadRepository
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function paginateByProject(
        string $projectId,
        ListQuery $query,
        ?string $correlationId = null,
    ): TaskPage {
        $startedAt = microtime(true);
        $builder = ClientTask::query()->where('project_id', $projectId);

        $this->applyFilters($builder, $query);

        $total = (clone $builder)->count('id');

        $this->applySorting($builder, $query);

        /** @var Collection<int, ClientTask> $rows */
        $rows = $builder
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logger->info('repository.query', [
            'correlation_id' => $correlationId,
            'scope' => 'tasks.collection',
            'aggregate_id' => $projectId,
            'pagination_inputs' => $query->toArray(),
            'result_total' => $total,
            'elapsed_ms' => $elapsedMs,
        ]);

        $items = array_map(
            fn (ClientTask $row): TaskReadModel => $this->hydrateTask($row),
            $rows->all(),
        );

        $this->logger->info('repository.hydration', [
            'correlation_id' => $correlationId,
            'scope' => 'tasks.collection',
            'aggregate_id' => $projectId,
            'hydrated_count' => count($items),
        ]);

        return new TaskPage(items: $items, total: $total);
    }

    private function applyFilters(Builder $builder, ListQuery $query): void
    {
        if ($query->search !== null) {
            $builder->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($query->search).'%']);
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status);
        }

        if ($query->priority !== null) {
            $builder->where('priority', $query->priority);
        }
    }

    private function applySorting(Builder $builder, ListQuery $query): void
    {
        $direction = $query->direction;

        switch ($query->sort) {
            case 'createdAt':
                $builder->orderBy('created_at', $direction);
                break;

            case 'dueAt':
                if ($direction === 'asc') {
                    $builder->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END asc')->orderBy('due_at', 'asc');
                } else {
                    $builder->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END desc')->orderBy('due_at', 'desc');
                }
                break;

            case 'priority':
                $builder->orderByRaw(
                    "CASE priority WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'urgent' THEN 4 ELSE 0 END {$direction}",
                );
                break;

            default:
                $builder->orderBy('updated_at', $direction);
                break;
        }

        $builder->orderBy('id');
    }

    private function hydrateTask(ClientTask $row): TaskReadModel
    {
        return new TaskReadModel(
            id: (string) $row->getAttribute('id'),
            projectId: (string) $row->getAttribute('project_id'),
            title: (string) $row->getAttribute('title'),
            actorId: (string) $row->getAttribute('actor_id'),
            actorEmail: (string) $row->getAttribute('actor_email'),
            actorRole: TaskActorRole::from((string) $row->getAttribute('actor_role')),
            status: TaskStatus::from((string) $row->getAttribute('status')),
            priority: TaskPriority::from((string) $row->getAttribute('priority')),
            dueAt: $row->due_at !== null ? DateTimeImmutable::createFromInterface($row->due_at) : null,
            completedAt: $row->completed_at !== null ? DateTimeImmutable::createFromInterface($row->completed_at) : null,
            archived: (bool) $row->getAttribute('archived'),
            createdAt: DateTimeImmutable::createFromInterface($row->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($row->updated_at),
        );
    }
}