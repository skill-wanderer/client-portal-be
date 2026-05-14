<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectCollectionProjection;
use App\Domain\ClientPortal\Models\ProjectSummary;
use App\Support\Api\Contracts\ApiDataContract;
use App\Support\Api\Contracts\ListData;
use App\Support\Api\Contracts\PaginationMeta;
use App\Support\Api\Query\ListQuery;

final class ProjectCollectionData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectCollectionProjection $projection,
        private readonly PaginationMeta $pagination,
        private readonly ListQuery $query,
    ) {
    }

    public static function fromDomain(
        ProjectCollectionProjection $projection,
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
        return new ListData(
            items: array_map(
                static fn (ProjectSummary $project): ProjectData => ProjectData::fromDomain($project),
                $this->projection->items,
            ),
            pagination: $this->pagination,
            query: $this->query,
        )->toArray();
    }
}