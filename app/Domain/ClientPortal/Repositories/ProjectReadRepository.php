<?php

namespace App\Domain\ClientPortal\Repositories;

use App\Domain\ClientPortal\ReadModels\ProjectPage;
use App\Domain\ClientPortal\ReadModels\ProjectReadModel;
use App\Support\Api\Query\ListQuery;

interface ProjectReadRepository
{
    public function paginateByWorkspace(
        string $workspaceId,
        ListQuery $query,
        ?string $correlationId = null,
    ): ProjectPage;

    public function findById(string $projectId, ?string $correlationId = null): ?ProjectReadModel;
}