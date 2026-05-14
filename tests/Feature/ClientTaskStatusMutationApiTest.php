<?php

namespace Tests\Feature;

use App\Domain\ClientPortal\Write\Contracts\MutationEventRecorder;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Database\Seeders\ClientPortalReadModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClientTaskStatusMutationApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ClientPortalReadModelSeeder::class;

    public function test_task_status_completion_mutates_task_counter_version_and_records_event(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'project' => [
                    'id' => $projectId,
                    'status' => 'active',
                    'taskCount' => 18,
                    'activeTaskCount' => 18,
                    'completedTaskCount' => 12,
                    'version' => 2,
                ],
                'task' => [
                    'id' => $taskId,
                    'previousStatus' => 'in_progress',
                    'status' => 'done',
                    'version' => 2,
                ],
                'transition' => [
                    'from' => 'in_progress',
                    'to' => 'done',
                ],
                'workflow' => [
                    'projectTransition' => null,
                    'eventSequence' => ['TaskCompleted'],
                ],
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($taskId);

        $this->assertSame(12, $project->completed_task_count);
        $this->assertSame(18, $project->active_task_count);
        $this->assertSame(2, $project->version);
        $this->assertSame('done', $task->status);
        $this->assertSame(2, $task->version);
        $this->assertNotNull($task->completed_at);

        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'TaskCompleted',
            'aggregate_id' => $taskId,
            'workspace_id' => $this->workspaceId('user-123'),
            'actor_id' => 'user-123',
        ]);

        $this->assertDatabaseHas('client_mutation_idempotency', [
            'scope' => $this->statusScope($projectId, $taskId),
            'status' => 'completed',
            'aggregate_id' => $taskId,
            'response_status' => 200,
        ]);
    }

    public function test_task_status_reopen_decrements_counter_and_records_reopen_event(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-sso';

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'todo',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'project' => [
                    'id' => $projectId,
                    'status' => 'active',
                    'taskCount' => 18,
                    'activeTaskCount' => 18,
                    'completedTaskCount' => 10,
                    'version' => 2,
                ],
                'task' => [
                    'id' => $taskId,
                    'previousStatus' => 'done',
                    'status' => 'todo',
                    'version' => 2,
                    'completedAt' => null,
                ],
                'transition' => [
                    'from' => 'done',
                    'to' => 'todo',
                ],
                'workflow' => [
                    'projectTransition' => null,
                    'eventSequence' => ['TaskReopened'],
                ],
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($taskId);

        $this->assertSame(10, $project->completed_task_count);
        $this->assertSame(18, $project->active_task_count);
        $this->assertSame(2, $project->version);
        $this->assertSame('todo', $task->status);
        $this->assertSame(2, $task->version);
        $this->assertNull($task->completed_at);

        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'TaskReopened',
            'aggregate_id' => $taskId,
            'workspace_id' => $this->workspaceId('user-123'),
            'actor_id' => 'user-123',
        ]);
    }

    public function test_task_status_rejects_stale_write_with_conflict(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';

        $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertOk();

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'todo',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'conflict',
                'reason' => 'STALE_WRITE',
            ],
        ]);
    }

    public function test_task_status_rejects_illegal_transition_with_validation_error(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-sso';

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'in_progress',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'validation_error',
                'reason' => 'VALIDATION_ERROR',
            ],
        ]);

        $this->assertSame('done', ClientTask::query()->findOrFail($taskId)->status);
        $this->assertDatabaseCount('client_mutation_events', 0);
    }

    public function test_task_status_returns_not_found_for_cross_workspace_project(): void
    {
        $response = $this->authenticatedRequest('project-external-ops', 'project-external-ops-task-01', [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertNotFound()->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'project_not_found',
                'reason' => 'PROJECT_NOT_FOUND',
            ],
        ]);
    }

    public function test_task_status_replays_duplicate_idempotent_request_without_duplicate_mutation(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $idempotencyKey = (string) Str::uuid();

        $firstResponse = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => $idempotencyKey,
        ]);
        $secondResponse = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();
        $this->assertSame($firstResponse->json('data'), $secondResponse->json('data'));
        $this->assertNotNull(ClientTask::query()->findOrFail($taskId)->completed_at);
        $this->assertSame(2, ClientTask::query()->findOrFail($taskId)->version);
        $this->assertSame(1, \App\Models\ClientMutationEvent::query()->count());
        $this->assertSame(1, \App\Models\ClientMutationIdempotency::query()->count());
    }

    public function test_final_active_task_completion_auto_completes_project_and_ignores_archived_tasks(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $archivedTaskId = $projectId.'-task-links';

        $this->prepareProjectForFinalCompletion($projectId, $taskId, $archivedTaskId);

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'project' => [
                    'id' => $projectId,
                    'status' => 'completed',
                    'taskCount' => 18,
                    'activeTaskCount' => 17,
                    'completedTaskCount' => 17,
                    'version' => 2,
                ],
                'task' => [
                    'id' => $taskId,
                    'previousStatus' => 'in_progress',
                    'status' => 'done',
                    'version' => 2,
                ],
                'workflow' => [
                    'projectTransition' => [
                        'from' => 'active',
                        'to' => 'completed',
                        'reason' => 'all_active_tasks_completed',
                    ],
                    'eventSequence' => ['TaskCompleted', 'ProjectCompleted'],
                ],
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($taskId);
        $archivedTask = ClientTask::query()->findOrFail($archivedTaskId);

        $this->assertSame('completed', $project->status);
        $this->assertSame(17, $project->active_task_count);
        $this->assertSame(17, $project->completed_task_count);
        $this->assertSame(2, $project->version);
        $this->assertSame('done', $task->status);
        $this->assertTrue($archivedTask->archived);
        $this->assertSame('todo', $archivedTask->status);

        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'TaskCompleted',
            'aggregate_id' => $taskId,
            'workspace_id' => $this->workspaceId('user-123'),
        ]);
        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'ProjectCompleted',
            'aggregate_id' => $projectId,
            'workspace_id' => $this->workspaceId('user-123'),
        ]);
    }

    public function test_reopening_task_auto_reopens_completed_project(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-sso';

        $this->prepareProjectForReopen($projectId);

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'todo',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'project' => [
                    'id' => $projectId,
                    'status' => 'active',
                    'taskCount' => 18,
                    'activeTaskCount' => 18,
                    'completedTaskCount' => 17,
                    'version' => 2,
                ],
                'task' => [
                    'id' => $taskId,
                    'previousStatus' => 'done',
                    'status' => 'todo',
                    'version' => 2,
                    'completedAt' => null,
                ],
                'workflow' => [
                    'projectTransition' => [
                        'from' => 'completed',
                        'to' => 'active',
                        'reason' => 'active_task_reopened',
                    ],
                    'eventSequence' => ['TaskReopened', 'ProjectReopened'],
                ],
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);

        $this->assertSame('active', $project->status);
        $this->assertSame(18, $project->active_task_count);
        $this->assertSame(17, $project->completed_task_count);
        $this->assertSame(2, $project->version);

        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'ProjectReopened',
            'aggregate_id' => $projectId,
            'workspace_id' => $this->workspaceId('user-123'),
        ]);
    }

    public function test_final_completion_replay_does_not_duplicate_project_workflow_events(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $archivedTaskId = $projectId.'-task-links';
        $idempotencyKey = (string) Str::uuid();

        $this->prepareProjectForFinalCompletion($projectId, $taskId, $archivedTaskId);

        $firstResponse = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => $idempotencyKey,
        ]);
        $secondResponse = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();
        $this->assertSame($firstResponse->json('data'), $secondResponse->json('data'));
        $this->assertSame('completed', ClientProject::query()->findOrFail($projectId)->status);
        $this->assertSame(1, \App\Models\ClientMutationEvent::query()->where('name', 'TaskCompleted')->count());
        $this->assertSame(1, \App\Models\ClientMutationEvent::query()->where('name', 'ProjectCompleted')->count());
        $this->assertSame(1, \App\Models\ClientMutationIdempotency::query()->count());
    }

    public function test_final_completion_rolls_back_when_project_workflow_event_recording_fails(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $archivedTaskId = $projectId.'-task-links';

        $this->prepareProjectForFinalCompletion($projectId, $taskId, $archivedTaskId);

        $recorder = Mockery::mock(MutationEventRecorder::class);
        $recorder
            ->shouldReceive('record')
            ->once()
            ->withArgs(static fn ($event): bool => $event->name === 'TaskCompleted');
        $recorder
            ->shouldReceive('record')
            ->once()
            ->withArgs(static fn ($event): bool => $event->name === 'ProjectCompleted')
            ->andThrow(new RuntimeException('forced-project-workflow-failure'));
        $this->app->instance(MutationEventRecorder::class, $recorder);

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertStatus(500)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'internal_error',
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($taskId);

        $this->assertSame('active', $project->status);
        $this->assertSame(18, $project->task_count);
        $this->assertSame(17, $project->active_task_count);
        $this->assertSame(16, $project->completed_task_count);
        $this->assertSame(1, $project->version);
        $this->assertSame('in_progress', $task->status);
        $this->assertSame(1, $task->version);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseCount('client_mutation_events', 0);
        $this->assertDatabaseCount('client_mutation_idempotency', 0);
    }

    public function test_final_completion_rejects_stale_workflow_mutation_deterministically(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $archivedTaskId = $projectId.'-task-links';

        $this->prepareProjectForFinalCompletion($projectId, $taskId, $archivedTaskId);

        $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertOk();

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'todo',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'conflict',
                'reason' => 'STALE_WRITE',
            ],
        ]);
    }

    public function test_task_status_rolls_back_when_event_recording_fails(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';
        $recorder = Mockery::mock(MutationEventRecorder::class);
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('forced-event-failure'));
        $this->app->instance(MutationEventRecorder::class, $recorder);

        $response = $this->authenticatedRequest($projectId, $taskId, [
            'status' => 'done',
            'expectedVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(),
        ]);

        $response->assertStatus(500)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'internal_error',
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($taskId);

        $this->assertSame(11, $project->completed_task_count);
        $this->assertSame(1, $project->version);
        $this->assertSame('in_progress', $task->status);
        $this->assertSame(1, $task->version);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseCount('client_mutation_events', 0);
        $this->assertDatabaseCount('client_mutation_idempotency', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticatedRequest(string $projectId, string $taskId, array $payload): \Illuminate\Testing\TestResponse
    {
        $sessionService = Mockery::mock(SessionService::class);
        $sessionService
            ->shouldReceive('getSession')
            ->once()
            ->with('test-session-id')
            ->andReturn(new SessionData(
                sessionId: 'test-session-id',
                userSub: 'user-123',
                userEmail: 'test@reltroner.com',
                userRole: 'client',
                expiresAt: CarbonImmutable::now()->addHour(),
            ));

        $this->app->instance(SessionService::class, $sessionService);

        return $this->call(
            'PATCH',
            '/api/v1/client/projects/'.$projectId.'/tasks/'.$taskId.'/status',
            $payload,
            ['__session' => 'test-session-id'],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    private function workspaceId(string $userSub): string
    {
        return 'workspace-'.substr(sha1($userSub), 0, 10);
    }

    private function ownedProjectId(string $userSub): string
    {
        $workspaceId = $this->workspaceId($userSub);
        $workspaceSuffix = substr(sha1($workspaceId), 0, 8);

        return 'project-'.$workspaceSuffix.'-atlas';
    }

    private function statusScope(string $projectId, string $taskId): string
    {
        return 'task.status.update:'.$this->workspaceId('user-123').':'.$projectId.':'.$taskId;
    }

    private function prepareProjectForFinalCompletion(
        string $projectId,
        string $remainingTaskId,
        ?string $archivedTaskId = null,
    ): void {
        ClientTask::query()
            ->where('project_id', $projectId)
            ->update([
                'status' => 'done',
                'completed_at' => '2026-05-10 15:00:00',
                'archived' => false,
                'version' => 1,
            ]);

        ClientTask::query()
            ->where('id', $remainingTaskId)
            ->update([
                'status' => 'in_progress',
                'completed_at' => null,
                'archived' => false,
                'version' => 1,
            ]);

        $taskCount = (int) ClientTask::query()->where('project_id', $projectId)->count();
        $activeTaskCount = $taskCount;
        $completedTaskCount = $taskCount - 1;

        if ($archivedTaskId !== null) {
            ClientTask::query()
                ->where('id', $archivedTaskId)
                ->update([
                    'status' => 'todo',
                    'completed_at' => null,
                    'archived' => true,
                    'version' => 1,
                ]);

            $activeTaskCount -= 1;
            $completedTaskCount -= 1;
        }

        ClientProject::query()
            ->where('id', $projectId)
            ->update([
                'status' => 'active',
                'archived' => false,
                'task_count' => $taskCount,
                'active_task_count' => $activeTaskCount,
                'completed_task_count' => $completedTaskCount,
                'version' => 1,
            ]);
    }

    private function prepareProjectForReopen(string $projectId): void
    {
        ClientTask::query()
            ->where('project_id', $projectId)
            ->update([
                'status' => 'done',
                'completed_at' => '2026-05-10 15:00:00',
                'archived' => false,
                'version' => 1,
            ]);

        ClientProject::query()
            ->where('id', $projectId)
            ->update([
                'status' => 'completed',
                'archived' => false,
                'task_count' => 18,
                'active_task_count' => 18,
                'completed_task_count' => 18,
                'version' => 1,
            ]);
    }
}