<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\ProjectOwner;
use App\Support\Api\Contracts\ApiDataContract;

final class ProjectOwnerData implements ApiDataContract
{
    public function __construct(
        private readonly ProjectOwner $owner,
    ) {
    }

    public static function fromDomain(ProjectOwner $owner): self
    {
        return new self($owner);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->owner->id,
            'email' => $this->owner->email,
            'role' => $this->owner->role->value,
        ];
    }
}