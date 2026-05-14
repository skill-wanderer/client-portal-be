<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectTaskCollectionProjection;
use App\Domain\ClientPortal\Models\TaskSummary;
use App\Support\Api\Contracts\ApiDataContract;
use App\Support\Api\Contracts\ListData;
use App\Support\Api\Contracts\PaginationMeta;
use App\Support\Api\Query\ListQuery;

final class TaskCollectionData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectTaskCollectionProjection $projection,
        private readonly PaginationMeta $pagination,
        private readonly ListQuery $query,
    ) {
    }

    public static function fromDomain(
        ProjectTaskCollectionProjection $projection,
        PaginationMeta $pagination,
        ListQuery $query,
    ): self {
        return new self($projection, $pagination, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $list = new ListData(
            items: array_map(
                static fn (TaskSummary $task): TaskSummaryData => TaskSummaryData::fromDomain($task),
                $this->projection->items,
            ),
            pagination: $this->pagination,
            query: $this->query,
        );
        $listPayload = $list->toArray();

        return [
            'project' => TaskProjectData::fromDomain($this->projection->project)->toArray(),
            'items' => $listPayload['items'],
            'meta' => $listPayload['meta'] ?? [],
        ];
    }
}