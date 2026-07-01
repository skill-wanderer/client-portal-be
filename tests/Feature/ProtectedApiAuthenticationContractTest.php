<?php

namespace Tests\Feature;

use App\Services\Auth\BearerTokenValidator;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Feature\Concerns\UsesProtectedApiAuth;
use Tests\TestCase;

class ProtectedApiAuthenticationContractTest extends TestCase
{
    use UsesProtectedApiAuth;

    public function test_protected_routes_require_bearer_validation_before_session_loading(): void
    {
        foreach ($this->protectedRouteMiddlewareExpectations() as [$method, $uri, $expectedMiddleware]) {
            $middleware = $this->middlewareFor($method, $uri);

            foreach ($expectedMiddleware as $middlewareName) {
                $this->assertContains($middlewareName, $middleware, $method.' '.$uri);
            }

            if (in_array('bearer.validate', $expectedMiddleware, true) && in_array('session.load', $expectedMiddleware, true)) {
                $this->assertMiddlewareBefore($middleware, 'bearer.validate', 'session.load', $method.' '.$uri);
            }
        }
    }

    public function test_public_auth_and_runtime_routes_do_not_require_bearer_or_session_middleware(): void
    {
        foreach ($this->publicRouteRequests() as [$method, $uri]) {
            $middleware = $this->middlewareFor($method, $uri);

            $this->assertNotContains('bearer.validate', $middleware, $method.' '.$uri);
            $this->assertNotContains('session.load', $middleware, $method.' '.$uri);
        }
    }

    public function test_no_route_loads_session_without_prior_bearer_validation(): void
    {
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('session.load', $middleware, true)) {
                continue;
            }

            $this->assertContains('bearer.validate', $middleware, $route->uri());
            $this->assertMiddlewareBefore($middleware, 'bearer.validate', 'session.load', $route->uri());
        }
    }

    public function test_protected_api_routes_reject_cookie_only_session(): void
    {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator->shouldNotReceive('validate');
        $this->app->instance(BearerTokenValidator::class, $validator);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldNotReceive('getSession');
        $this->app->instance(SessionService::class, $sessionService);

        foreach ($this->protectedRouteRequests() as [$method, $uri, $payload]) {
            $response = $this->call(
                $method,
                $uri,
                $payload,
                ['__session' => 'test-session-id'],
                [],
                ['HTTP_ACCEPT' => 'application/json'],
            );

            $response
                ->assertUnauthorized()
                ->assertJson([
                    'success' => false,
                    'data' => null,
                    'error' => [
                        'code' => 'unauthorized',
                        'reason' => 'NO_BEARER_TOKEN',
                    ],
                ]);
        }
    }

    public function test_protected_api_rejects_valid_bearer_token_with_missing_session_header(): void
    {
        $this->bindValidBearerToken();

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldNotReceive('getSession');
        $this->app->instance(SessionService::class, $sessionService);

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer valid-access-token',
            ])
            ->getJson('/v1/auth/me');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'NO_SESSION_ID',
                ],
            ]);
    }

    public function test_protected_api_rejects_valid_bearer_token_with_invalid_session_id(): void
    {
        $this->bindValidBearerToken();

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService
            ->shouldReceive('getSession')
            ->once()
            ->with('missing-session-id')
            ->andReturn(null);
        $sessionService
            ->shouldReceive('deleteSession')
            ->once()
            ->with('missing-session-id');
        $this->app->instance(SessionService::class, $sessionService);

        $response = $this->call(
            'GET',
            '/v1/auth/me',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(sessionId: 'missing-session-id'),
        );

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'NO_SESSION',
                ],
            ]);
    }

    public function test_protected_api_rejects_missing_bearer_token_with_valid_session_id(): void
    {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator->shouldNotReceive('validate');
        $this->app->instance(BearerTokenValidator::class, $validator);

        $sessionService = Mockery::mock(SessionService::class);
        $sessionService->shouldNotReceive('getSession');
        $this->app->instance(SessionService::class, $sessionService);

        $response = $this
            ->withHeaders([
                'X-Session-Id' => 'test-session-id',
            ])
            ->getJson('/v1/auth/me');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'NO_BEARER_TOKEN',
                ],
            ]);
    }

    public function test_protected_api_accepts_valid_bearer_token_and_valid_session_id(): void
    {
        $this->bindValidBearerToken();
        $this->bindSession();

        $response = $this->call(
            'GET',
            '/v1/auth/me',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(),
        );

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_protected_api_rejects_token_session_subject_mismatch(): void
    {
        $this->bindValidBearerToken(subject: 'other-user');
        $this->bindSession();

        $response = $this->call(
            'GET',
            '/v1/auth/me',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(),
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'forbidden',
                    'reason' => 'TOKEN_SESSION_MISMATCH',
                ],
            ]);
    }

    public function test_protected_api_rejects_token_session_role_mismatch(): void
    {
        $this->bindValidBearerToken(role: 'admin');
        $this->bindSession(role: 'client');

        $response = $this->call(
            'GET',
            '/v1/auth/me',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(),
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'forbidden',
                    'reason' => 'TOKEN_SESSION_MISMATCH',
                ],
            ]);
    }

    public function test_protected_api_preserves_role_based_access_denial(): void
    {
        $this->bindValidBearerToken(role: 'admin');
        $this->bindSession(role: 'admin');

        $response = $this->call(
            'GET',
            '/api/v1/client/projects',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(),
        );

        $response
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'forbidden',
                    'reason' => 'AUTH_RBAC_DENIED',
                ],
            ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function protectedRouteRequests(): array
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';

        return [
            ['GET', '/v1/auth/me', []],
            ['GET', '/api/v1/client/projects', []],
            ['GET', '/api/v1/client/projects/'.$projectId, []],
            ['GET', '/api/v1/client/projects/'.$projectId.'/tasks', []],
            ['POST', '/api/v1/client/projects/'.$projectId.'/tasks', [
                'title' => 'Prepare onboarding flow',
                'description' => 'Draft onboarding steps for new clients',
                'priority' => 'high',
                'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
            ]],
            ['PATCH', '/api/v1/client/projects/'.$projectId.'/tasks/'.$taskId.'/status', [
                'status' => 'done',
                'expectedVersion' => 1,
                'idempotencyKey' => '11111111-1111-4111-8111-111111111111',
            ]],
            ['GET', '/api/v1/client/users/user-123', []],
            ['GET', '/api/v1/admin/users/admin-123', []],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: array<int, string>}>
     */
    private function protectedRouteMiddlewareExpectations(): array
    {
        $projectId = $this->ownedProjectId('user-123');
        $taskId = $projectId.'-task-scope';

        return [
            ['GET', '/v1/auth/me', ['bearer.validate', 'session.load']],
            ['GET', '/api/v1/client/dashboard', ['dashboard.audit', 'keycloak.token']],
            ['GET', '/api/v1/client/projects', ['projects.list.audit', 'bearer.validate', 'session.load', 'rbac:client']],
            ['GET', '/api/v1/client/projects/'.$projectId, ['projects.detail.audit', 'bearer.validate', 'session.load', 'rbac:client']],
            ['GET', '/api/v1/client/projects/'.$projectId.'/tasks', ['tasks.collection.audit', 'bearer.validate', 'session.load', 'rbac:client']],
            ['POST', '/api/v1/client/projects/'.$projectId.'/tasks', ['bearer.validate', 'session.load', 'rbac:client']],
            ['PATCH', '/api/v1/client/projects/'.$projectId.'/tasks/'.$taskId.'/status', ['bearer.validate', 'session.load', 'rbac:client']],
            ['GET', '/api/v1/client/users/user-123', ['bearer.validate', 'session.load', 'rbac:client', 'owner']],
            ['GET', '/api/v1/admin/users/admin-123', ['bearer.validate', 'session.load', 'rbac:admin']],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function publicRouteRequests(): array
    {
        return [
            ['GET', '/up'],
            ['GET', '/api/v1/test-db'],
            ['GET', '/v1/auth/runtime/health'],
            ['GET', '/v1/auth/runtime/deployment'],
            ['GET', '/v1/auth/login'],
            ['GET', '/v1/auth/callback'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function middlewareFor(string $method, string $uri): array
    {
        return Route::getRoutes()
            ->match(Request::create($uri, $method))
            ->gatherMiddleware();
    }

    /**
     * @param array<int, string> $middleware
     */
    private function assertMiddlewareBefore(
        array $middleware,
        string $first,
        string $second,
        string $context,
    ): void {
        $firstIndex = array_search($first, $middleware, true);
        $secondIndex = array_search($second, $middleware, true);

        $this->assertIsInt($firstIndex, $context);
        $this->assertIsInt($secondIndex, $context);
        $this->assertLessThan($secondIndex, $firstIndex, $context);
    }

    private function bindSession(string $subject = 'user-123', string $role = 'client'): void
    {
        $sessionService = Mockery::mock(SessionService::class);
        $sessionService
            ->shouldReceive('getSession')
            ->once()
            ->with('test-session-id')
            ->andReturn(new SessionData(
                sessionId: 'test-session-id',
                userSub: $subject,
                userEmail: 'test@reltroner.com',
                userRole: $role,
                expiresAt: CarbonImmutable::now()->addHour(),
            ));

        $this->app->instance(SessionService::class, $sessionService);
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

