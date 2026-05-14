<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\WorkspaceContext;
use App\Support\Api\Contracts\ApiDataContract;

final class ProjectWorkspaceData implements ApiDataContract
{
    public function __construct(
        private readonly WorkspaceContext $workspace,
    ) {
    }

    public static function fromDomain(WorkspaceContext $workspace): self
    {
        return new self($workspace);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->workspace->id,
            'name' => $this->workspace->name,
            'status' => $this->workspace->status->value,
        ];

        if ($this->workspace->ownershipRole !== null) {
            $payload['ownershipRole'] = $this->workspace->ownershipRole->value;
        }

        return $payload;
    }
}