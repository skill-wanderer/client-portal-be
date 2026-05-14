<?php

namespace Tests\Unit\ClientPortal\Write;

use App\Domain\ClientPortal\Write\Contracts\ProjectWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Domain\ClientPortal\Write\Contracts\MutationIdempotencyStore;
use App\Domain\ClientPortal\Write\Contracts\TaskWriteRepository;
use App\Domain\ClientPortal\Write\Contracts\WriteTransactionManager;
use App\Domain\ClientPortal\Write\Contracts\WorkspaceWriteRepository;
use ReflectionMethod;
use Tests\TestCase;

class WriteRepositoryBoundaryTest extends TestCase
{
    public function test_project_write_repository_uses_write_side_aggregate_states(): void
    {
        $method = new ReflectionMethod(ProjectWriteRepository::class, 'findOwnedProject');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame(
            'App\\Domain\\ClientPortal\\Write\\Models\\ProjectAggregateState',
            $returnType?->getName(),
        );
    }

    public function test_task_write_repository_uses_write_side_aggregate_states(): void
    {
        $method = new ReflectionMethod(TaskWriteRepository::class, 'findOwnedTask');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame(
            'App\\Domain\\ClientPortal\\Write\\Models\\TaskAggregateState',
            $returnType?->getName(),
        );
    }

    public function test_workspace_write_repository_resolves_write_contexts_not_read_models(): void
    {
        $method = new ReflectionMethod(WorkspaceWriteRepository::class, 'findOwnedWorkspace');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame(
            'App\\Domain\\ClientPortal\\Write\\Models\\WorkspaceMutationContext',
            $returnType?->getName(),
        );
    }

    public function test_write_foundation_contracts_expose_transaction_idempotency_and_event_boundaries(): void
    {
        $transactionMethod = new ReflectionMethod(WriteTransactionManager::class, 'within');
        $reservationMethod = new ReflectionMethod(MutationIdempotencyStore::class, 'reserve');
        $recordMethod = new ReflectionMethod(MutationEventRecorder::class, 'record');

        $this->assertSame('within', $transactionMethod->getName());
        $this->assertSame('reserve', $reservationMethod->getName());
        $this->assertSame('record', $recordMethod->getName());
    }
}