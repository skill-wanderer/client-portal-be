<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectSummary;
use App\Support\Api\Contracts\ApiDataContract;

final class ProjectData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectSummary $project,
    ) {
    }

    public static function fromDomain(ProjectSummary $project): self
    {
        return new self($project);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->project->id,
            'name' => $this->project->name,
            'status' => $this->project->status->value,
        ];

        if ($this->project->createdAt !== null) {
            $payload['createdAt'] = $this->project->createdAt->format(DATE_ATOM);
        }

        if ($this->project->updatedAt !== null) {
            $payload['updatedAt'] = $this->project->updatedAt->format(DATE_ATOM);
        }

        return $payload;
    }
}