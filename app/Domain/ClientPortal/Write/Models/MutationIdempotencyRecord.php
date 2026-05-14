<?php

namespace App\Domain\ClientPortal\Write\Models;

final class MutationIdempotencyRecord
{
    /**
     * @param array<string, mixed>|null $responsePayload
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $key,
        public readonly string $requestHash,
        public readonly string $status,
        public readonly ?string $aggregateId = null,
        public readonly ?int $responseStatus = null,
        public readonly ?array $responsePayload = null,
    ) {
    }

    public function completed(): bool
    {
        return $this->status === 'completed';
    }

    public function pending(): bool
    {
        return $this->status === 'pending';
    }
}