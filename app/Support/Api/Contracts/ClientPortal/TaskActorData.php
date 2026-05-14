<?php

namespace App\Support\Api\Contracts\ClientPortal;

use App\Domain\ClientPortal\Models\TaskActor;
use App\Support\Api\Contracts\ApiDataContract;

final class TaskActorData implements ApiDataContract
{
    public function __construct(
        private readonly TaskActor $actor,
    ) {
    }

    public static function fromDomain(TaskActor $actor): self
    {
        return new self($actor);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->actor->id,
            'email' => $this->actor->email,
            'role' => $this->actor->role->value,
        ];
    }
}