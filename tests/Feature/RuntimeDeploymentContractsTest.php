<?php

namespace Tests\Feature;

use Monolog\Formatter\JsonFormatter;
use Tests\TestCase;

class RuntimeDeploymentContractsTest extends TestCase
{
    public function test_runtime_health_endpoint_returns_the_deployment_health_contract(): void
    {
        $this->setValidRuntimeConfig();

        $response = $this->getJson('/v1/auth/runtime/health');

        $response
            ->assertOk()
            ->assertHeader('X-Deployment-ID', 'backend-test-deploy')
            ->assertHeader('X-Contract-Version', 'contract-test-v1')
            ->assertHeader('X-Correlation-ID')
            ->assertHeader('X-Request-ID')
            ->assertJson([
                'success' => true,
                'data' => [
                    'deployment_id' => 'backend-test-deploy',
                    'contract_version' => 'contract-test-v1',
                    'runtime_status' => 'healthy',
                    'auth_runtime_status' => 'healthy',
                    'config_validation_status' => 'healthy',
                ],
            ]);

        $this->assertSame('healthy', $response->json('data.checks.deployment_metadata.status'));
        $this->assertSame('healthy', $response->json('data.checks.auth_alignment.status'));
        $this->assertSame([], $response->json('data.issues'));
    }

    public function test_runtime_deployment_endpoint_reports_aligned_frontend_metadata(): void
    {
        $this->setValidRuntimeConfig();

        $response = $this
            ->withHeaders([
                'X-Correlation-ID' => 'deploy-verify-correlation-123',
                'X-Deployment-ID' => 'backend-test-deploy',
                'X-Contract-Version' => 'contract-test-v1',
            ])
            ->getJson('/v1/auth/runtime/deployment');

        $response
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'deploy-verify-correlation-123')
            ->assertHeader('X-Deployment-ID', 'backend-test-deploy')
            ->assertHeader('X-Contract-Version', 'contract-test-v1')
            ->assertHeader('X-Request-ID')
            ->assertJson([
                'success' => true,
                'data' => [
                    'deployment_id' => 'backend-test-deploy',
                    'contract_version' => 'contract-test-v1',
                    'runtime_status' => 'healthy',
                    'compatibility_status' => 'healthy',
                    'frontend_runtime' => [
                        'status' => 'healthy',
                        'metadata_present' => true,
                        'deployment_id' => 'backend-test-deploy',
                        'contract_version' => 'contract-test-v1',
                        'deployment_match' => true,
                        'contract_match' => true,
                    ],
                    'expected_frontend_headers' => [
                        'X-Deployment-ID' => 'backend-test-deploy',
                        'X-Contract-Version' => 'contract-test-v1',
                    ],
                ],
            ]);
    }

    public function test_runtime_health_endpoint_classifies_auth_alignment_mismatch_as_incompatible(): void
    {
        $this->setValidRuntimeConfig();
        config()->set('keycloak.redirect_uri', 'https://client-portal-api.com/callback');

        $response = $this->getJson('/v1/auth/runtime/health');

        $response
            ->assertStatus(503)
            ->assertJson([
                'success' => true,
                'data' => [
                    'runtime_status' => 'incompatible',
                    'auth_runtime_status' => 'incompatible',
                    'config_validation_status' => 'healthy',
                ],
            ]);

        $this->assertSame('incompatible', $response->json('data.checks.auth_alignment.status'));
        $this->assertSame('KC_REDIRECT_URI_MISMATCH', $response->json('data.issues.0.code'));
    }

    public function test_runtime_validation_command_fails_on_blocking_runtime_config(): void
    {
        $this->setValidRuntimeConfig();
        config()->set('app.deployment_id', 'local-dev');

        $this->artisan('runtime:validate --fail-on-invalid')->assertExitCode(1);
    }

    private function setValidRuntimeConfig(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://client-portal-api.com');
        config()->set('app.frontend_app_url', 'https://client.skill-wanderer.com');
        config()->set('app.deployment_id', 'backend-test-deploy');
        config()->set('app.contract_version', 'contract-test-v1');
        config()->set('app.trusted_proxies', '10.0.0.0/8');
        config()->set('session.driver', 'redis');
        config()->set('session.connection', 'default');
        config()->set('database.redis.client', 'predis');
        config()->set('database.redis.default.host', 'redis');
        config()->set('database.redis.default.port', '6379');
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stack.channels', ['stderr']);
        config()->set('logging.channels.stderr.formatter', JsonFormatter::class);
        config()->set('cors.allowed_origins', ['https://client.skill-wanderer.com']);
        config()->set('keycloak.base_url', 'https://sso.skill-wanderer.com');
        config()->set('keycloak.issuer', 'https://sso.skill-wanderer.com/realms/client-portal');
        config()->set('keycloak.client_id', 'client-portal-fe');
        config()->set('keycloak.client_secret', 'runtime-secret');
        config()->set('keycloak.redirect_uri', 'https://client-portal-api.com/v1/auth/callback');
        config()->set('keycloak.authorization_endpoint', 'https://sso.skill-wanderer.com/realms/client-portal/protocol/openid-connect/auth');
        config()->set('keycloak.token_endpoint', 'https://sso.skill-wanderer.com/realms/client-portal/protocol/openid-connect/token');
        config()->set('keycloak.frontend_dashboard_url', 'https://client.skill-wanderer.com/dashboard');
    }
}