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
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClientTaskCreateApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ClientPortalReadModelSeeder::class;

    public function test_task_create_persists_child_aggregate_counter_version_and_event(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $workspaceId = $this->workspaceId('user-123');
        $idempotencyKey = '11111111-1111-4111-8111-111111111111';
        $expectedTaskId = $this->deterministicTaskId($workspaceId, $projectId, $idempotencyKey);

        $response = $this->authenticatedRequest($projectId, [
            'title' => 'Prepare onboarding flow',
            'description' => 'Draft onboarding steps for new clients',
            'priority' => 'high',
            'idempotencyKey' => $idempotencyKey,
        ]);

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'data' => [
                'project' => [
                    'id' => $projectId,
                    'taskCount' => 19,
                    'version' => 2,
                ],
                'task' => [
                    'id' => $expectedTaskId,
                    'title' => 'Prepare onboarding flow',
                    'description' => 'Draft onboarding steps for new clients',
                    'priority' => 'high',
                    'status' => 'todo',
                    'archived' => false,
                    'version' => 1,
                    'completedAt' => null,
                ],
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);
        $task = ClientTask::query()->findOrFail($expectedTaskId);

        $this->assertSame(19, $project->task_count);
        $this->assertSame(19, $project->active_task_count);
        $this->assertSame(2, $project->version);
        $this->assertSame('Prepare onboarding flow', $task->title);
        $this->assertSame('Draft onboarding steps for new clients', $task->description);
        $this->assertSame('todo', $task->status);
        $this->assertSame('high', $task->priority);
        $this->assertFalse($task->archived);
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $task->version);

        $this->assertDatabaseHas('client_mutation_events', [
            'name' => 'TaskCreated',
            'aggregate_id' => $expectedTaskId,
            'workspace_id' => $workspaceId,
            'actor_id' => 'user-123',
        ]);

        $this->assertDatabaseHas('client_mutation_idempotency', [
            'scope' => $this->createScope($projectId),
            'status' => 'completed',
            'aggregate_id' => $expectedTaskId,
            'response_status' => 201,
        ]);
    }

    public function test_task_create_replays_duplicate_idempotent_request_without_duplicate_task_or_counter_increment(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $workspaceId = $this->workspaceId('user-123');
        $idempotencyKey = '11111111-1111-4111-8111-111111111111';
        $expectedTaskId = $this->deterministicTaskId($workspaceId, $projectId, $idempotencyKey);
        $payload = [
            'title' => 'Prepare onboarding flow',
            'description' => 'Draft onboarding steps for new clients',
            'priority' => 'high',
            'idempotencyKey' => $idempotencyKey,
        ];

        $firstResponse = $this->authenticatedRequest($projectId, $payload);
        $secondResponse = $this->authenticatedRequest($projectId, $payload);

        $firstResponse->assertStatus(201);
        $secondResponse->assertStatus(201);
        $this->assertSame($firstResponse->json('data'), $secondResponse->json('data'));
        $this->assertSame(1, ClientTask::query()->where('id', $expectedTaskId)->count());
        $this->assertSame(19, ClientProject::query()->findOrFail($projectId)->task_count);
        $this->assertSame(19, ClientProject::query()->findOrFail($projectId)->active_task_count);
        $this->assertSame(2, ClientProject::query()->findOrFail($projectId)->version);
        $this->assertSame(1, \App\Models\ClientMutationEvent::query()->count());
        $this->assertSame(1, \App\Models\ClientMutationIdempotency::query()->count());
    }

    public function test_task_create_rejects_reused_idempotency_key_for_different_payload(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $idempotencyKey = '11111111-1111-4111-8111-111111111111';

        $this->authenticatedRequest($projectId, [
            'title' => 'Prepare onboarding flow',
            'description' => 'Draft onboarding steps for new clients',
            'priority' => 'high',
            'idempotencyKey' => $idempotencyKey,
        ])->assertStatus(201);

        $response = $this->authenticatedRequest($projectId, [
            'title' => 'Prepare onboarding flow v2',
            'description' => 'Different payload should conflict',
            'priority' => 'high',
            'idempotencyKey' => $idempotencyKey,
        ]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'conflict',
                'reason' => 'IDEMPOTENCY_KEY_REUSED',
            ],
        ]);
    }

    public function test_task_create_returns_not_found_for_cross_workspace_project(): void
    {
        $response = $this->authenticatedRequest('project-external-ops', [
            'title' => 'Prepare onboarding flow',
            'description' => 'Draft onboarding steps for new clients',
            'priority' => 'high',
            'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
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

    public function test_task_create_rejects_archived_project_with_validation_error(): void
    {
        $projectId = $this->ownedProjectId('user-123', 'knowledge');

        $response = $this->authenticatedRequest($projectId, [
            'title' => 'Archive-safe task creation',
            'description' => 'This should be rejected.',
            'priority' => 'medium',
            'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'validation_error',
                'reason' => 'VALIDATION_ERROR',
            ],
        ]);

        $this->assertSame(14, ClientProject::query()->findOrFail($projectId)->task_count);
        $this->assertDatabaseCount('client_mutation_events', 0);
    }

    public function test_task_create_rolls_back_when_event_recording_fails(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $recorder = Mockery::mock(MutationEventRecorder::class);
        $recorder->shouldReceive('record')->andThrow(new RuntimeException('forced-event-failure'));
        $this->app->instance(MutationEventRecorder::class, $recorder);

        $response = $this->authenticatedRequest($projectId, [
            'title' => 'Prepare onboarding flow',
            'description' => 'Draft onboarding steps for new clients',
            'priority' => 'high',
            'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
        ]);

        $response->assertStatus(500)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'internal_error',
            ],
        ]);

        $project = ClientProject::query()->findOrFail($projectId);

        $this->assertSame(18, $project->task_count);
        $this->assertSame(18, $project->active_task_count);
        $this->assertSame(1, $project->version);
        $this->assertSame(18, ClientTask::query()->where('project_id', $projectId)->count());
        $this->assertDatabaseCount('client_mutation_events', 0);
        $this->assertDatabaseCount('client_mutation_idempotency', 0);
    }

    public function test_task_create_rejects_invalid_payload_with_validation_error(): void
    {
        $projectId = $this->ownedProjectId('user-123');

        $response = $this->authenticatedRequest($projectId, [
            'title' => '',
            'priority' => 'not-a-priority',
            'idempotencyKey' => 'not-a-uuid',
        ]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'validation_error',
                'reason' => 'VALIDATION_ERROR',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function authenticatedRequest(string $projectId, array $payload): \Illuminate\Testing\TestResponse
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
            'POST',
            '/api/v1/client/projects/'.$projectId.'/tasks',
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

    private function ownedProjectId(string $userSub, string $suffix = 'atlas'): string
    {
        $workspaceId = $this->workspaceId($userSub);
        $workspaceSuffix = substr(sha1($workspaceId), 0, 8);

        return 'project-'.$workspaceSuffix.'-'.$suffix;
    }

    private function deterministicTaskId(string $workspaceId, string $projectId, string $idempotencyKey): string
    {
        return $projectId.'-task-gen-'.substr(hash('sha256', implode('|', [
            $workspaceId,
            $projectId,
            $idempotencyKey,
        ])), 0, 16);
    }

    private function createScope(string $projectId): string
    {
        return 'task.create:'.$this->workspaceId('user-123').':'.$projectId;
    }
}