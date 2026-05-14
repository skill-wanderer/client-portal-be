<?php

namespace Tests\Feature;

use App\Models\ClientTask;
use Database\Seeders\ClientPortalReadModelSeeder;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ClientProjectTasksApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ClientPortalReadModelSeeder::class;

    public function test_project_tasks_require_an_authenticated_session(): void
    {
        $response = $this->getJson('/api/v1/client/projects/'.$this->ownedProjectId('user-123').'/tasks');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                ],
            ]);
    }

    public function test_project_tasks_return_owned_child_collection_with_parent_context(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $response = $this->authenticatedRequest($projectId);

        $response->assertOk();
        $this->assertSame($projectId, $response->json('data.project.id'));
        $this->assertSame('Atlas Migration', $response->json('data.project.name'));
        $this->assertSame('active', $response->json('data.project.status'));
        $this->assertSame('shared', $response->json('data.project.visibility'));
        $this->assertSame(18, $response->json('data.meta.pagination.total'));
        $this->assertSame('Audit migration scope', $response->json('data.items.0.title'));
        $this->assertSame('user-123', $response->json('data.items.0.actor.id'));
        $this->assertSame('assignee', $response->json('data.items.0.actor.role'));
        $this->assertSame('in_progress', $response->json('data.items.0.lifecycle.status'));
        $this->assertSame('urgent', $response->json('data.items.0.lifecycle.priority'));
        $this->assertSame('2026-05-12T12:00:00+00:00', $response->json('data.items.0.lifecycle.dueAt'));
        $this->assertSame(false, $response->json('data.items.0.lifecycle.archived'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
            'priority' => null,
        ], $response->json('data.meta.query'));
    }

    public function test_project_tasks_return_not_found_for_unknown_project(): void
    {
        $response = $this->authenticatedRequest('project-does-not-exist');

        $response->assertNotFound()->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'project_not_found',
                'reason' => 'PROJECT_NOT_FOUND',
            ],
        ]);
    }

    public function test_project_tasks_return_not_found_for_cross_workspace_project(): void
    {
        $response = $this->authenticatedRequest('project-external-ops');

        $response->assertNotFound()->assertJson([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'project_not_found',
                'reason' => 'PROJECT_NOT_FOUND',
            ],
        ]);
    }

    public function test_project_tasks_normalize_pagination_contract(): void
    {
        $response = $this->authenticatedRequest($this->ownedProjectId('user-123'), [
            'page' => '2',
            'per_page' => '5',
        ]);

        $response->assertOk();
        $response->assertJsonCount(5, 'data.items');
        $this->assertSame([
            'page' => 2,
            'perPage' => 5,
            'total' => 18,
            'totalPages' => 4,
        ], $response->json('data.meta.pagination'));
        $this->assertSame([
            'page' => 2,
            'perPage' => 5,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
            'priority' => null,
        ], $response->json('data.meta.query'));
    }

    public function test_project_tasks_normalize_filters_and_nested_dto_serialization(): void
    {
        $response = $this->authenticatedRequest($this->ownedProjectId('user-123'), [
            'search' => 'sso',
            'status' => 'done',
            'priority' => 'high',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $this->assertSame('Confirm SSO dependencies', $response->json('data.items.0.title'));
        $this->assertSame('reviewer', $response->json('data.items.0.actor.role'));
        $this->assertSame('done', $response->json('data.items.0.lifecycle.status'));
        $this->assertSame('high', $response->json('data.items.0.lifecycle.priority'));
        $this->assertSame('2026-05-06T16:00:00+00:00', $response->json('data.items.0.lifecycle.completedAt'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => 'sso',
            'status' => 'done',
            'priority' => 'high',
        ], $response->json('data.meta.query'));
    }

    public function test_project_tasks_sort_supported_fields_and_fall_back_safely_for_invalid_sort(): void
    {
        $supportedSortResponse = $this->authenticatedRequest($this->ownedProjectId('user-123'), [
            'sort' => 'dueAt',
        ]);

        $supportedSortResponse->assertOk();
        $this->assertSame('Confirm SSO dependencies', $supportedSortResponse->json('data.items.0.title'));
        $this->assertSame('asc', $supportedSortResponse->json('data.meta.query.direction'));

        $invalidSortResponse = $this->authenticatedRequest($this->ownedProjectId('user-123'), [
            'sort' => 'dropTable',
            'direction' => 'asc',
        ]);

        $invalidSortResponse->assertOk();
        $this->assertSame('Audit migration scope', $invalidSortResponse->json('data.items.0.title'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
            'priority' => null,
        ], $invalidSortResponse->json('data.meta.query'));
    }

    public function test_project_tasks_are_loaded_from_persistence_records(): void
    {
        ClientTask::query()
            ->where('id', $this->ownedProjectId('user-123').'-task-scope')
            ->update([
                'title' => 'Persisted scope task',
                'updated_at' => '2026-05-11 18:00:00',
            ]);

        $response = $this->authenticatedRequest($this->ownedProjectId('user-123'));

        $response->assertOk();
        $this->assertSame('Persisted scope task', $response->json('data.items.0.title'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $query
     */
    private function authenticatedRequest(string $projectId, array $query = []): \Illuminate\Testing\TestResponse
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
            'GET',
            '/api/v1/client/projects/'.$projectId.'/tasks',
            $query,
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
}