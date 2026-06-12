<?php

namespace Tests\Feature;

use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class ClientDashboardApiTest extends TestCase
{
    public function test_dashboard_requires_an_authenticated_session(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');

        $response = $this->getJson('/api/v1/client/dashboard');

        $response
            ->assertUnauthorized()
            ->assertHeader('X-Deployment-ID', 'backend-test-deploy')
            ->assertHeader('X-Contract-Version', 'contract-test-v1')
            ->assertHeader('X-Correlation-ID')
            ->assertHeader('X-Request-ID')
            ->assertJson([
                'success' => false,
                'data' => null,
                'deployment_id' => 'backend-test-deploy',
                'contract_version' => 'contract-test-v1',
                'error' => [
                    'code' => 'unauthorized',
                    'failure_code' => 'BE_SESSION_EXPIRED',
                    'recovery_hint' => 'reauthenticate',
                    'retryable' => false,
                    'runtime_boundary' => 'backend_session',
                ],
            ]);

        $this->assertSame(
            $response->headers->get('X-Correlation-ID'),
            $response->json('correlation_id'),
        );
        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('request_id'),
        );
    }

    public function test_dashboard_returns_the_authenticated_user_and_ready_payload(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');

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

        $response = $this->call(
            'GET',
            '/api/v1/client/dashboard',
            [],
            ['__session' => 'test-session-id'],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response
            ->assertOk()
            ->assertHeader('X-Deployment-ID', 'backend-test-deploy')
            ->assertHeader('X-Contract-Version', 'contract-test-v1')
            ->assertHeader('X-Correlation-ID')
            ->assertHeader('X-Request-ID')
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 'user-123',
                        'email' => 'test@reltroner.com',
                    ],
                    'dashboard' => [
                        'status' => 'ready',
                    ],
                    'summary' => [
                        'activeProjects' => 0,
                        'pendingActions' => 0,
                        'unreadMessages' => 0,
                        'recentFiles' => 0,
                    ],
                    'projects' => [],
                    'tasks' => [],
                    'files' => [],
                ],
            ]);
    }

    public function test_dashboard_rejects_stale_frontend_runtime_metadata(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');

        $response = $this->withHeaders([
            'X-Deployment-ID' => 'frontend-stale-build',
            'X-Contract-Version' => 'contract-test-v0',
            'X-Correlation-ID' => 'fe-runtime-correlation-123',
        ])->getJson('/api/v1/client/dashboard');

        $response
            ->assertStatus(412)
            ->assertHeader('X-Correlation-ID', 'fe-runtime-correlation-123')
            ->assertHeader('X-Deployment-ID', 'backend-test-deploy')
            ->assertHeader('X-Contract-Version', 'contract-test-v1')
            ->assertHeader('X-Request-ID')
            ->assertJson([
                'success' => false,
                'data' => null,
                'correlation_id' => 'fe-runtime-correlation-123',
                'deployment_id' => 'backend-test-deploy',
                'contract_version' => 'contract-test-v1',
                'error' => [
                    'code' => 'runtime_skew',
                    'reason' => 'DEPLOYMENT_SKEW',
                    'failure_code' => 'BE_DEPLOYMENT_SKEW',
                    'recovery_hint' => 'reload_runtime',
                    'retryable' => true,
                    'runtime_boundary' => 'backend_runtime',
                ],
            ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}