<?php

namespace Tests\Feature;

use App\Http\Middleware\BearerTokenMiddleware;
use App\Services\Auth\BearerTokenValidator;
use App\Services\Auth\Exceptions\InvalidBearerTokenException;
use App\Services\Auth\ValidatedBearerToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class BearerTokenMiddlewareTest extends TestCase
{
    public function test_token_validation_middleware_rejects_missing_authorization_header(): void
    {
        $this->bindValidatorThatShouldNotRun();
        $this->registerBearerProbeRoute();

        $response = $this->getJson('/__test/bearer-probe');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'NO_BEARER_TOKEN',
                    'failure_code' => 'BE_BEARER_TOKEN_INVALID',
                    'runtime_boundary' => 'backend_auth',
                ],
            ]);
    }

    public function test_token_validation_middleware_rejects_malformed_authorization_header(): void
    {
        $this->bindValidatorThatShouldNotRun();
        $this->registerBearerProbeRoute();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Token not-a-bearer-token',
            ])
            ->getJson('/__test/bearer-probe');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'MALFORMED_BEARER_TOKEN',
                ],
            ]);
    }

    public function test_token_validation_middleware_rejects_cookie_only_auth_data(): void
    {
        $this->bindValidatorThatShouldNotRun();
        $this->registerBearerProbeRoute();

        $response = $this->call(
            'GET',
            '/__test/bearer-probe',
            [],
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

    public function test_token_validation_middleware_rejects_invalid_bearer_token(): void
    {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator
            ->shouldReceive('validate')
            ->once()
            ->with('invalid-access-token')
            ->andThrow(new InvalidBearerTokenException('INVALID_BEARER_TOKEN'));
        $this->app->instance(BearerTokenValidator::class, $validator);
        $this->registerBearerProbeRoute();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer invalid-access-token',
            ])
            ->getJson('/__test/bearer-probe');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'unauthorized',
                    'reason' => 'INVALID_BEARER_TOKEN',
                ],
            ]);
    }

    public function test_token_validation_middleware_accepts_valid_bearer_token_and_attaches_context(): void
    {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator
            ->shouldReceive('validate')
            ->once()
            ->with('valid-access-token')
            ->andReturn(new ValidatedBearerToken(
                subject: 'user-123',
                email: 'test@reltroner.com',
                role: 'client',
                expiresAt: CarbonImmutable::now()->addHour(),
                issuer: 'https://sso.skill-wanderer.com/realms/client-portal',
                audience: ['client-portal-fe'],
                authorizedParty: 'client-portal-fe',
            ));
        $this->app->instance(BearerTokenValidator::class, $validator);
        $this->registerBearerProbeRoute();

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer valid-access-token',
            ])
            ->getJson('/__test/bearer-probe');

        $response
            ->assertOk()
            ->assertJson([
                'subject' => 'user-123',
                'email' => 'test@reltroner.com',
                'role' => 'client',
                'issuer' => 'https://sso.skill-wanderer.com/realms/client-portal',
                'audience' => ['client-portal-fe'],
                'authorizedParty' => 'client-portal-fe',
            ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function bindValidatorThatShouldNotRun(): void
    {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator->shouldNotReceive('validate');
        $this->app->instance(BearerTokenValidator::class, $validator);
    }

    private function registerBearerProbeRoute(): void
    {
        Route::middleware('bearer.validate')->get('/__test/bearer-probe', function (Request $request) {
            $token = $request->attributes->get(BearerTokenMiddleware::REQUEST_ATTRIBUTE);

            if (! $token instanceof ValidatedBearerToken) {
                return response()->json([
                    'attached' => false,
                ], 500);
            }

            return response()->json([
                'subject' => $token->subject,
                'email' => $token->email,
                'role' => $token->role,
                'issuer' => $token->issuer,
                'audience' => $token->audience,
                'authorizedParty' => $token->authorizedParty,
            ]);
        });
    }
}
