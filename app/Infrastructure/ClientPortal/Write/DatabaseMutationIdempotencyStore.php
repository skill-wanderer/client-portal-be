<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Models\MutationIdempotencyRecord;
use App\Models\ClientMutationIdempotency;
use Illuminate\Database\QueryException;

final class DatabaseMutationIdempotencyStore implements MutationIdempotencyStore
{
    public function find(string $scope, string $key): ?MutationIdempotencyRecord
    {
        /** @var ClientMutationIdempotency|null $record */
        $record = ClientMutationIdempotency::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();

        if (! $record instanceof ClientMutationIdempotency) {
            return null;
        }

        return new MutationIdempotencyRecord(
            scope: (string) $record->scope,
            key: (string) $record->idempotency_key,
            requestHash: (string) $record->request_hash,
            status: (string) $record->status,
            aggregateId: $record->aggregate_id !== null ? (string) $record->aggregate_id : null,
            responseStatus: $record->response_status !== null ? (int) $record->response_status : null,
            responsePayload: is_array($record->response_payload) ? $record->response_payload : null,
        );
    }

    public function reserve(string $scope, string $key, string $requestHash): bool
    {
        try {
            ClientMutationIdempotency::query()->create([
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => 'pending',
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    public function complete(
        string $scope,
        string $key,
        string $aggregateId,
        int $responseStatus,
        array $responsePayload,
    ): void {
        ClientMutationIdempotency::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->update([
                'status' => 'completed',
                'aggregate_id' => $aggregateId,
                'response_status' => $responseStatus,
                'response_payload' => $responsePayload,
                'updated_at' => now(),
            ]);
    }

    public function release(string $scope, string $key): void
    {
        ClientMutationIdempotency::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->delete();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000' && in_array($driverCode, [19, 1062], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate key value')
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}