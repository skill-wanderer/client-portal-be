<?php

namespace App\Domain\ClientPortal\Write\Models;

use App\Domain\ClientPortal\Enums\ProjectStatus;

final class ProjectWorkflowTransition
{
    public function __construct(
        public readonly ProjectStatus $from,
        public readonly ProjectStatus $to,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            from: ProjectStatus::from((string) $payload['from']),
            to: ProjectStatus::from((string) $payload['to']),
            reason: (string) $payload['reason'],
        );
    }
}