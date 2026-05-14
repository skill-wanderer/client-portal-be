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
        $response = $this->getJson('/api/v1/client/dashboard');

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

    public function test_dashboard_returns_the_authenticated_user_and_ready_payload(): void
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}