<?php

namespace Tests\Feature;

use App\Services\Auth\BearerTokenValidator;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\Feature\Concerns\UsesProtectedApiAuth;
use Tests\TestCase;

class ProtectedApiAuthenticationContractTest extends TestCase
{
    use UsesProtectedApiAuth;

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
            ->getJson('/api/v1/client/dashboard');

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
            '/api/v1/client/dashboard',
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
            ->getJson('/api/v1/client/dashboard');

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
            '/api/v1/client/dashboard',
            [],
            [],
            [],
            $this->protectedApiServerHeaders(),
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
                ],
            ]);
    }

    public function test_protected_api_rejects_token_session_subject_mismatch(): void
    {
        $this->bindValidBearerToken(subject: 'other-user');
        $this->bindSession();

        $response = $this->call(
            'GET',
            '/api/v1/client/dashboard',
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
            '/api/v1/client/dashboard',
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
            ['GET', '/api/v1/client/dashboard', []],
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
        ];
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
