<?php

namespace Tests\Feature;

use App\Services\Auth\AuthService;
use App\Services\Auth\LoginRedirectData;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class CloudflareDeploymentHardeningTest extends TestCase
{
    public function test_login_marks_state_cookie_secure_when_https_is_forwarded_by_a_trusted_proxy(): void
    {
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
            ->get('/v1/auth/login');

        $response->assertRedirect('https://sso.skill-wanderer.test/authorize');

        $cookie = $this->cookieFromResponse($response, '__state');

        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_login_keeps_state_cookie_non_secure_for_local_http_requests(): void
    {
        config()->set('session.secure', null);
        config()->set('session.domain', null);
        config()->set('session.path', '/');
        config()->set('session.same_site', 'lax');

        $this->mockLoginRedirect();

        $response = $this->get('/v1/auth/login');

        $response->assertRedirect('https://sso.skill-wanderer.test/authorize');

        $cookie = $this->cookieFromResponse($response, '__state');

        $this->assertFalse($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
    }

    public function test_invalid_callback_request_expires_state_cookie_with_runtime_cookie_policy(): void
    {
        config()->set('session.secure', null);
        config()->set('session.domain', null);
        config()->set('session.path', '/');
        config()->set('session.same_site', 'lax');

        $response = $this
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'api.skill-wanderer.test',
                'X-Forwarded-Port' => '443',
            ])
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.10',
            ])
            ->get('/v1/auth/callback');

        $response->assertStatus(400);

        $cookie = $this->cookieFromResponse($response, '__state');

        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_auth_routes_allow_localhost_and_loopback_frontend_origins(): void
    {
        foreach (['http://127.0.0.1:3000', 'http://localhost:3000'] as $origin) {
            $response = $this->withHeaders([
                'Origin' => $origin,
            ])->get('/v1/auth/me');

            $response->assertStatus(401);
            $response->assertHeader('Access-Control-Allow-Origin', $origin);
            $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
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