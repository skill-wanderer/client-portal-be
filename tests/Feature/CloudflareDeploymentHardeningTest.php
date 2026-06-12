<?php

namespace Tests\Feature;

use App\Services\Auth\AuthService;
use App\Services\Auth\LoginRedirectData;
use App\Support\Security\AuthCookieSettings;
use App\Support\Security\AuthStateCookieData;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class CloudflareDeploymentHardeningTest extends TestCase
{
    public function test_login_marks_state_cookie_secure_when_https_is_forwarded_by_a_trusted_proxy(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');
        config()->set('session.secure', null);
        config()->set('session.domain', null);
        config()->set('session.path', '/');
        config()->set('session.same_site', 'lax');

        $this->mockLoginRedirect();

        $response = $this
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'api.skill-wanderer.test',
                'X-Forwarded-Port' => '443',
            ])
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.10',
            ])
            ->get('/v1/auth/login?cid=fe-correlation-123&auth_flow_id=auth-flow-123');

        $response->assertRedirect('https://sso.skill-wanderer.test/authorize');
        $response->assertHeader('X-Correlation-ID', 'fe-correlation-123');
        $response->assertHeader('X-Deployment-ID', 'backend-test-deploy');
        $response->assertHeader('X-Contract-Version', 'contract-test-v1');
        $response->assertHeader('X-Request-ID');

        $cookie = $this->cookieFromResponse($response, '__state');
        $stateCookie = AuthStateCookieData::decode($cookie->getValue());

        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('generated-state', $stateCookie?->state);
        $this->assertSame('fe-correlation-123', $stateCookie?->correlationId);
        $this->assertSame('auth-flow-123', $stateCookie?->authFlowId);
    }

    public function test_login_keeps_state_cookie_non_secure_for_local_http_requests(): void
    {
        config()->set('app.url', 'http://127.0.0.1:8003');
        config()->set('session.secure', null);

        $request = Request::create('http://127.0.0.1:8003/v1/auth/login', 'GET');

        $this->assertFalse(AuthCookieSettings::secure($request));
    }

    public function test_invalid_callback_request_expires_state_cookie_with_runtime_cookie_policy(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');
        config()->set('session.secure', null);
        config()->set('session.domain', null);
        config()->set('session.path', '/');
        config()->set('session.same_site', 'lax');

        $response = $this->call(
            'GET',
            '/v1/auth/callback',
            [],
            [
                '__state' => (new AuthStateCookieData(
                    state: 'generated-state',
                    correlationId: 'fe-correlation-456',
                    authFlowId: 'auth-flow-456',
                ))->encode(),
            ],
            [],
            [
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'api.skill-wanderer.test',
                'HTTP_X_FORWARDED_PORT' => '443',
                'REMOTE_ADDR' => '10.0.0.10',
            ],
        );

        $response->assertStatus(400);
        $response->assertHeader('X-Correlation-ID', 'fe-correlation-456');
        $response->assertHeader('X-Deployment-ID', 'backend-test-deploy');
        $response->assertHeader('X-Contract-Version', 'contract-test-v1');
        $response->assertHeader('X-Request-ID');
        $response->assertJson([
            'success' => false,
            'data' => null,
            'correlation_id' => 'fe-correlation-456',
            'deployment_id' => 'backend-test-deploy',
            'contract_version' => 'contract-test-v1',
            'error' => [
                'code' => 'invalid_request',
                'failure_code' => 'BE_SESSION_EXPIRED',
                'recovery_hint' => 'restart_auth_flow',
                'retryable' => false,
                'runtime_boundary' => 'backend_auth',
            ],
        ]);
        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('request_id'),
        );

        $cookie = $this->cookieFromResponse($response, '__state');

        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_auth_routes_allow_the_configured_frontend_origin(): void
    {
        config()->set('cors.allowed_origins', ['https://client.skill-wanderer.com']);

        $response = $this->withHeaders([
            'Origin' => 'https://client.skill-wanderer.com',
        ])->get('/v1/auth/me');

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://client.skill-wanderer.com');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader(
            'Access-Control-Expose-Headers',
            'X-Correlation-ID, X-Request-ID, X-Deployment-ID, X-Contract-Version, X-Mutation-ID, X-Replay-Group-ID, X-Idempotent-Replay'
        );
    }

    public function test_auth_routes_classify_denied_frontend_origin(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');
        config()->set('cors.allowed_origins', ['https://client.skill-wanderer.com']);

        $response = $this->withHeaders([
            'Origin' => 'https://evil.skill-wanderer.test',
            'X-Correlation-ID' => 'cors-correlation-123',
        ])->get('/v1/auth/me');

        $response->assertStatus(403);
        $response->assertHeader('X-Correlation-ID', 'cors-correlation-123');
        $response->assertHeader('X-Deployment-ID', 'backend-test-deploy');
        $response->assertHeader('X-Contract-Version', 'contract-test-v1');
        $response->assertHeader('X-Request-ID');
        $response->assertJson([
            'success' => false,
            'data' => null,
            'correlation_id' => 'cors-correlation-123',
            'deployment_id' => 'backend-test-deploy',
            'contract_version' => 'contract-test-v1',
            'error' => [
                'code' => 'forbidden',
                'reason' => 'CORS_DENIED',
                'failure_code' => 'BE_CORS_DENIED',
                'recovery_hint' => 'verify_origin',
                'retryable' => false,
                'runtime_boundary' => 'backend_runtime',
            ],
        ]);
    }

    public function test_runtime_guard_classifies_invalid_forwarded_proto_as_proxy_mismatch(): void
    {
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'ftp',
            'X-Correlation-ID' => 'proxy-correlation-123',
        ])->get('/v1/auth/me');

        $response->assertStatus(502);
        $response->assertHeader('X-Correlation-ID', 'proxy-correlation-123');
        $response->assertHeader('X-Deployment-ID', 'backend-test-deploy');
        $response->assertHeader('X-Contract-Version', 'contract-test-v1');
        $response->assertHeader('X-Request-ID');
        $response->assertJson([
            'success' => false,
            'data' => null,
            'correlation_id' => 'proxy-correlation-123',
            'deployment_id' => 'backend-test-deploy',
            'contract_version' => 'contract-test-v1',
            'error' => [
                'code' => 'proxy_mismatch',
                'reason' => 'PROXY_MISMATCH',
                'failure_code' => 'BE_PROXY_MISMATCH',
                'recovery_hint' => 'retry_later',
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

    private function mockLoginRedirect(): void
    {
        $authService = Mockery::mock(AuthService::class);
        $authService
            ->shouldReceive('beginLogin')
            ->once()
            ->with('/', false)
            ->andReturn(new LoginRedirectData(
                state: 'generated-state',
                authorizationUrl: 'https://sso.skill-wanderer.test/authorize',
                returnTo: '/',
                forceReauth: false,
            ));

        $this->app->instance(AuthService::class, $authService);
    }

    private function cookieFromResponse(\Illuminate\Testing\TestResponse $response, string $name): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail('Cookie not found: '.$name);
    }
}