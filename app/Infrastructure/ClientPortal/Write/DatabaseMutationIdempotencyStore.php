<?php

namespace App\Infrastructure\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Models\MutationIdempotencyRecord;
use App\Models\ClientMutationIdempotency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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
            correlationId: $record->correlation_id !== null ? (string) $record->correlation_id : null,
            mutationId: $record->mutation_id !== null ? (string) $record->mutation_id : null,
            replayGroupId: $record->replay_group_id !== null ? (string) $record->replay_group_id : null,
            aggregateId: $record->aggregate_id !== null ? (string) $record->aggregate_id : null,
            responseStatus: $record->response_status !== null ? (int) $record->response_status : null,
            responsePayload: is_array($record->response_payload) ? $record->response_payload : null,
        );
    }

    public function reserve(
        string $scope,
        string $key,
        string $requestHash,
        ?string $correlationId = null,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
    ): bool
    {
        try {
            ClientMutationIdempotency::query()->create([
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => 'pending',
                'correlation_id' => $correlationId,
                'mutation_id' => $mutationId,
                'replay_group_id' => $replayGroupId,
            ]);

            Log::info('be.mutation.idempotency.reserved', [
                'scope' => $scope,
                'idempotency_status' => 'pending',
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                Log::warning('be.mutation.idempotency.conflict', [
                    'scope' => $scope,
                    'reason' => 'IDEMPOTENCY_RESERVATION_FAILED',
                ]);

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
        ?string $correlationId = null,
        ?string $mutationId = null,
        ?string $replayGroupId = null,
    ): void {
        ClientMutationIdempotency::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->update([
                'status' => 'completed',
                'correlation_id' => $correlationId,
                'mutation_id' => $mutationId,
                'replay_group_id' => $replayGroupId,
                'aggregate_id' => $aggregateId,
                'response_status' => $responseStatus,
                'response_payload' => $responsePayload,
                'updated_at' => now(),
            ]);

        Log::info('be.mutation.idempotency.completed', [
            'scope' => $scope,
            'aggregate_id' => $aggregateId,
            'response_status' => $responseStatus,
        ]);
    }

    public function release(string $scope, string $key): void
    {
        ClientMutationIdempotency::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->delete();

        Log::info('be.mutation.idempotency.released', [
            'scope' => $scope,
        ]);
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