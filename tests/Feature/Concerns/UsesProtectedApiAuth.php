<?php

namespace Tests\Feature\Concerns;

use App\Services\Auth\BearerTokenValidator;
use App\Services\Auth\ValidatedBearerToken;
use Carbon\CarbonImmutable;
use Mockery;

trait UsesProtectedApiAuth
{
    protected function bindValidBearerToken(
        string $subject = 'user-123',
        string $email = 'test@reltroner.com',
        string $role = 'client',
        string $token = 'valid-access-token',
    ): void {
        $validator = Mockery::mock(BearerTokenValidator::class);
        $validator
            ->shouldReceive('validate')
            ->once()
            ->with($token)
            ->andReturn(new ValidatedBearerToken(
                subject: $subject,
                email: $email,
                role: $role,
                expiresAt: CarbonImmutable::now()->addHour(),
                issuer: 'https://sso.skill-wanderer.com/realms/client-portal',
                audience: ['client-portal-fe'],
                authorizedParty: 'client-portal-fe',
            ));

        $this->app->instance(BearerTokenValidator::class, $validator);
    }

    /**
     * @return array<string, string>
     */
    protected function protectedApiServerHeaders(
        string $sessionId = 'test-session-id',
        string $token = 'valid-access-token',
    ): array {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_SESSION_ID' => $sessionId,
        ];
    }
}
