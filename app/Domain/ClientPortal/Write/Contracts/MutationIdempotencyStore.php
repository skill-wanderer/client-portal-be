<?php

namespace App\Domain\ClientPortal\Write\Contracts;

use App\Domain\ClientPortal\Write\Models\MutationIdempotencyRecord;

interface MutationIdempotencyStore
{
    public function find(string $scope, string $key): ?MutationIdempotencyRecord;

    public function reserve(string $scope, string $key, string $requestHash): bool;

    /**
     * @param array<string, mixed> $responsePayload
     */
    public function complete(
        string $scope,
        string $key,
        string $aggregateId,
        int $responseStatus,
        array $responsePayload,
    ): void;

    public function release(string $scope, string $key): void;
}