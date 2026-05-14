<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectStats;
use App\Support\Api\Contracts\ApiDataContract;

final class ProjectStatsData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectStats $stats,
    ) {
    }

    public static function fromDomain(ProjectStats $stats): self
    {
        return new self($stats);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'taskCount' => $this->stats->taskCount,
            'completedTaskCount' => $this->stats->completedTaskCount,
            'fileCount' => $this->stats->fileCount,
            'pendingActionCount' => $this->stats->pendingActionCount,
        ];
    }
}