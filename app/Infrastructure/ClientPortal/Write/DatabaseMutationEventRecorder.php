<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Domain\ClientPortal\Write\Models\MutationEvent;
use App\Models\ClientMutationEvent;
use Illuminate\Support\Str;

final class DatabaseMutationEventRecorder implements MutationEventRecorder
{
    public function record(MutationEvent $event): void
    {
        ClientMutationEvent::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $event->name,
            'aggregate_id' => $event->aggregateId,
            'workspace_id' => $event->workspaceId,
            'actor_id' => isset($event->payload['actor_id']) ? (string) $event->payload['actor_id'] : null,
            'actor_email' => isset($event->payload['actor_email']) ? (string) $event->payload['actor_email'] : null,
            'correlation_id' => $event->correlationId,
            'payload' => $event->payload,
        ]);
    }
}