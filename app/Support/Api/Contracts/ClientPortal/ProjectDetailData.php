<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectDetailProjection;
use App\Support\Api\Contracts\ApiDataContract;

final class ProjectDetailData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectDetailProjection $project,
    ) {
    }

    public static function fromDomain(ProjectDetailProjection $project): self
    {
        return new self($project);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->project->id,
            'name' => $this->project->name,
            'description' => $this->project->description,
            'status' => $this->project->status->value,
            'visibility' => $this->project->visibility->value,
            'archived' => $this->project->archived,
            'createdAt' => $this->project->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->project->updatedAt->format(DATE_ATOM),
            'owner' => ProjectOwnerData::fromDomain($this->project->owner)->toArray(),
            'workspace' => ProjectWorkspaceData::fromDomain($this->project->workspace)->toArray(),
            'stats' => ProjectStatsData::fromDomain($this->project->stats)->toArray(),
        ];
    }
}