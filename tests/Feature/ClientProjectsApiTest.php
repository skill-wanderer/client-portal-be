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

class ClientProjectsApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ClientPortalReadModelSeeder::class;

    public function test_projects_require_an_authenticated_session(): void
    {
        $response = $this->getJson('/api/v1/client/projects');

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

    public function test_projects_return_owned_collection_with_default_sorting(): void
    {
        $response = $this->authenticatedRequest();

        $response->assertOk();
        $response->assertJsonCount(3, 'data.items');
        $this->assertSame('Atlas Migration', $response->json('data.items.0.name'));
        $this->assertSame('Client Onboarding Refresh', $response->json('data.items.1.name'));
        $this->assertSame('Knowledge Base Rollout', $response->json('data.items.2.name'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'total' => 3,
            'totalPages' => 1,
        ], $response->json('data.meta.pagination'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
        ], $response->json('data.meta.query'));
    }

    public function test_projects_normalize_pagination_contract(): void
    {
        $response = $this->authenticatedRequest([
            'page' => '2',
            'per_page' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $this->assertSame('Client Onboarding Refresh', $response->json('data.items.0.name'));
        $this->assertSame([
            'page' => 2,
            'perPage' => 1,
            'total' => 3,
            'totalPages' => 3,
        ], $response->json('data.meta.pagination'));
        $this->assertSame([
            'page' => 2,
            'perPage' => 1,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
        ], $response->json('data.meta.query'));
    }

    public function test_projects_normalize_search_and_status_filters(): void
    {
        $response = $this->authenticatedRequest([
            'search' => 'client',
            'status' => 'on_hold',
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $this->assertSame('Client Onboarding Refresh', $response->json('data.items.0.name'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => 'client',
            'status' => 'on_hold',
        ], $response->json('data.meta.query'));
    }

    public function test_projects_sort_supported_fields_and_fall_back_safely_for_invalid_sort(): void
    {
        $supportedSortResponse = $this->authenticatedRequest([
            'sort' => 'name',
        ]);

        $supportedSortResponse->assertOk();
        $this->assertSame('Atlas Migration', $supportedSortResponse->json('data.items.0.name'));
        $this->assertSame('Client Onboarding Refresh', $supportedSortResponse->json('data.items.1.name'));
        $this->assertSame('Knowledge Base Rollout', $supportedSortResponse->json('data.items.2.name'));
        $this->assertSame('asc', $supportedSortResponse->json('data.meta.query.direction'));

        $invalidSortResponse = $this->authenticatedRequest([
            'sort' => 'dropTable',
            'direction' => 'asc',
        ]);

        $invalidSortResponse->assertOk();
        $this->assertSame('Atlas Migration', $invalidSortResponse->json('data.items.0.name'));
        $this->assertSame([
            'page' => 1,
            'perPage' => 20,
            'sort' => 'updatedAt',
            'direction' => 'desc',
            'search' => null,
            'status' => null,
        ], $invalidSortResponse->json('data.meta.query'));
    }

    public function test_projects_are_loaded_from_persistence_records(): void
    {
        ClientProject::query()
            ->where('id', $this->ownedProjectId('user-123'))
            ->update(['name' => 'Atlas Migration Persisted']);

        $response = $this->authenticatedRequest();

        $response->assertOk();
        $this->assertSame('Atlas Migration Persisted', $response->json('data.items.0.name'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $query
     */
    private function authenticatedRequest(array $query = []): \Illuminate\Testing\TestResponse
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
            '/api/v1/client/projects',
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