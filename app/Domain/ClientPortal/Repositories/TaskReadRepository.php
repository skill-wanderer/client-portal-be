<?php

namespace App\Domain\ClientPortal\Repositories;

use App\Domain\ClientPortal\ReadModels\TaskPage;
use App\Support\Api\Query\ListQuery;

interface TaskReadRepository
{
    public function paginateByProject(
        string $projectId,
        ListQuery $query,
        ?string $correlationId = null,
    ): TaskPage;
}