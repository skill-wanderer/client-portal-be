<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientDashboardApiTest extends TestCase
{
    private const BASE_URL = 'https://sso.skill-wanderer.com';

    /**
     * @var array{kid: string, privateKey: string, jwks: array<string, mixed>}
     */
    private array $clientPortalKey;

    /**
     * @var array{kid: string, privateKey: string, jwks: array<string, mixed>}
     */
    private array $adminKey;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config()->set('keycloak.base_url', self::BASE_URL);
        config()->set('keycloak.allowed_realms', ['client-portal', 'skill-wanderer-admin']);
        config()->set('keycloak.expected_audience', 'client-portal-be');
        config()->set('keycloak.allowed_algorithms', ['RS256']);
        config()->set('keycloak.admin_realm', 'skill-wanderer-admin');
        config()->set('keycloak.admin_required_realm_role', 'client');
        config()->set('keycloak.jwks_cache_ttl_seconds', 60);
        config()->set('keycloak.clock_leeway_seconds', 0);

        $this->clientPortalKey = $this->generateRsaKeyPair('client-portal-key');
        $this->adminKey = $this->generateRsaKeyPair('skill-wanderer-admin-key');

        Http::fake([
            self::BASE_URL.'/realms/client-portal/protocol/openid-connect/certs' => Http::response($this->clientPortalKey['jwks']),
            self::BASE_URL.'/realms/skill-wanderer-admin/protocol/openid-connect/certs' => Http::response($this->adminKey['jwks']),
        ]);
    }

    public function test_dashboard_requires_a_keycloak_bearer_token(): void
    {
        $this->getJson('/api/v1/client/dashboard')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_dashboard_accepts_valid_client_portal_token(): void
    {
        $response = $this->authenticatedDashboardRequest($this->signClientPortalToken());

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 'client-user-123',
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

    public function test_dashboard_accepts_valid_admin_realm_token_with_client_role(): void
    {
        $response = $this->authenticatedDashboardRequest($this->signAdminToken());

        $response
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => 'admin-user-123',
                        'email' => 'nguyenhothienthanh26122003@gmail.com',
                    ],
                    'dashboard' => [
                        'status' => 'ready',
                    ],
                ],
            ]);
    }

    public function test_dashboard_rejects_token_with_wrong_audience(): void
    {
        $token = $this->signClientPortalToken([
            'aud' => ['account'],
        ]);

        $this->authenticatedDashboardRequest($token)
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_dashboard_forbids_admin_realm_token_without_client_role(): void
    {
        $token = $this->signAdminToken([
            'realm_access' => [
                'roles' => [
                    'offline_access',
                    'default-roles-skill-wanderer-admin',
                    'uma_authorization',
                    'Admin',
                ],
            ],
        ]);

        $this->authenticatedDashboardRequest($token)
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Forbidden.',
            ]);
    }

    private function authenticatedDashboardRequest(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/client/dashboard');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signClientPortalToken(array $overrides = []): string
    {
        return JWT::encode(
            array_replace($this->clientPortalClaims(), $overrides),
            $this->clientPortalKey['privateKey'],
            'RS256',
            $this->clientPortalKey['kid'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function signAdminToken(array $overrides = []): string
    {
        return JWT::encode(
            array_replace($this->adminClaims(), $overrides),
            $this->adminKey['privateKey'],
            'RS256',
            $this->adminKey['kid'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clientPortalClaims(): array
    {
        return [
            'iss' => self::BASE_URL.'/realms/client-portal',
            'sub' => 'client-user-123',
            'aud' => ['client-portal-be', 'account'],
            'azp' => 'client-portal-fe',
            'typ' => 'Bearer',
            'realm_access' => [
                'roles' => [
                    'default-roles-client-portal',
                    'offline_access',
                    'uma_authorization',
                ],
            ],
            'preferred_username' => 'testuser',
            'email' => 'test@reltroner.com',
            'name' => 'Test User',
            'iat' => time() - 60,
            'nbf' => time() - 60,
            'exp' => time() + 3600,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminClaims(): array
    {
        return [
            'iss' => self::BASE_URL.'/realms/skill-wanderer-admin',
            'sub' => 'admin-user-123',
            'aud' => ['client-portal-be', 'account'],
            'azp' => 'skill-wanderer-admin',
            'typ' => 'Bearer',
            'realm_access' => [
                'roles' => [
                    'offline_access',
                    'client',
                    'default-roles-skill-wanderer-admin',
                    'uma_authorization',
                    'Admin',
                ],
            ],
            'preferred_username' => 'skill-wanderer-admin',
            'email' => 'nguyenhothienthanh26122003@gmail.com',
            'name' => 'Skill Wanderer Admin',
            'iat' => time() - 60,
            'nbf' => time() - 60,
            'exp' => time() + 3600,
        ];
    }

    /**
     * @return array{kid: string, privateKey: string, jwks: array<string, mixed>}
     */
    private function generateRsaKeyPair(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath(),
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey, null, ['config' => $this->opensslConfigPath()])) {
            $this->fail('Unable to generate RSA key pair for Keycloak token tests.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            $this->fail('Unable to read RSA key details for Keycloak token tests.');
        }

        return [
            'kid' => $kid,
            'privateKey' => $privateKey,
            'jwks' => [
                'keys' => [
                    [
                        'kid' => $kid,
                        'kty' => 'RSA',
                        'use' => 'sig',
                        'alg' => 'RS256',
                        'n' => $this->base64UrlEncode($details['rsa']['n']),
                        'e' => $this->base64UrlEncode($details['rsa']['e']),
                    ],
                ],
            ],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function opensslConfigPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'client_portal_be_openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        }

        return $path;
    }
}
