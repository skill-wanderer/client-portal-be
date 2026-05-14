<?php

namespace Tests\Feature;

use App\Models\ClientProject;
use Database\Seeders\ClientPortalReadModelSeeder;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ClientProjectDetailApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ClientPortalReadModelSeeder::class;

    public function test_project_detail_requires_an_authenticated_session(): void
    {
        $response = $this->getJson('/api/v1/client/projects/'.$this->ownedProjectId('user-123'));

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

    public function test_project_detail_returns_owned_project_with_nested_dtos(): void
    {
        $projectId = $this->ownedProjectId('user-123');
        $response = $this->authenticatedRequest($projectId);

        $response->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'id' => $projectId,
                'name' => 'Atlas Migration',
                'description' => 'Coordinates the staged migration of legacy client workflows into the new portal.',
                'status' => 'active',
                'visibility' => 'shared',
                'archived' => false,
                'createdAt' => '2026-02-01T09:00:00+00:00',
                'updatedAt' => '2026-05-08T15:30:00+00:00',
                'owner' => [
                    'id' => 'user-123',
                    'email' => 'test@reltroner.com',
                    'role' => 'owner',
                ],
                'workspace' => [
                    'id' => $this->workspaceId('user-123'),
                    'name' => 'Client Workspace',
                    'status' => 'active',
                    'ownershipRole' => 'owner',
                ],
                'stats' => [
                    'taskCount' => 18,
                    'completedTaskCount' => 11,
                    'fileCount' => 7,
                    'pendingActionCount' => 3,
                ],
            ],
        ]);
    }

    public function test_project_detail_returns_not_found_for_unknown_project(): void
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

    public function test_project_detail_returns_not_found_for_cross_workspace_project(): void
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

    public function test_project_detail_is_hydrated_from_persistence_records(): void
    {
        ClientProject::query()
            ->where('id', $this->ownedProjectId('user-123'))
            ->update([
                'description' => 'Persisted detail description.',
                'file_count' => 9,
                'pending_action_count' => 1,
            ]);

        $response = $this->authenticatedRequest($this->ownedProjectId('user-123'));

        $response->assertOk();
        $this->assertSame('Persisted detail description.', $response->json('data.description'));
        $this->assertSame(9, $response->json('data.stats.fileCount'));
        $this->assertSame(1, $response->json('data.stats.pendingActionCount'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function authenticatedRequest(string $projectId): \Illuminate\Testing\TestResponse
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
            '/api/v1/client/projects/'.$projectId,
            [],
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