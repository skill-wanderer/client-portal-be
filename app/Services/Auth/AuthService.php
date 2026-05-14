<?php

namespace App\Services\Auth;

use App\Infrastructure\Keycloak\KeycloakClient;
use App\Infrastructure\Keycloak\TokenExchangeResponse;
use App\Services\Auth\Exceptions\InvalidStateException;
use App\Services\Auth\Exceptions\TokenExchangeException;
use App\Services\Session\Exceptions\SessionPersistenceException;
use App\Services\Session\SessionService;
use App\Support\Security\StateGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

class AuthService
{
    public function __construct(
        private readonly KeycloakClient $keycloakClient,
        private readonly SessionService $sessionService,
        private readonly StateGenerator $stateGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function beginLogin(string $returnTo = '/', bool $forceReauth = false): LoginRedirectData
    {
        $normalizedReturnTo = $returnTo !== '' ? $returnTo : '/';

        $this->logger->info('auth.login.start', [
            'return_to' => $normalizedReturnTo,
            'force_reauth' => $forceReauth,
        ]);

        $state = $this->stateGenerator->generate();
        $authorizationUrl = $this->keycloakClient->buildAuthorizationUrl($state);

        $this->logger->info('auth.login.redirect', [
            'authorization_endpoint' => config('keycloak.authorization_endpoint'),
            'state_hash' => hash('sha256', $state),
        ]);

        return new LoginRedirectData(
            state: $state,
            authorizationUrl: $authorizationUrl,
            returnTo: $normalizedReturnTo,
            forceReauth: $forceReauth,
        );
    }

    public function completeLogin(
        string $code,
        string $state,
        ?string $stateCookie,
        mixed $issuer,
        string $correlationId,
    ): CallbackRedirectData {
        $identityEmail = null;

        try {
            $this->logger->info('auth.callback.start', [
                'correlation_id' => $correlationId,
                'state_hash' => hash('sha256', $state),
            ]);

            $this->assertValidState($state, $stateCookie, $issuer);

            $tokenResponse = $this->keycloakClient->exchangeCode($code);
            $identity = $this->extractIdentityFromIdToken($tokenResponse);
            $identityEmail = $identity['email'];

            $createdSession = $this->sessionService->createSession(
                subject: $identity['sub'],
                email: $identity['email'],
                accessToken: $tokenResponse->accessToken,
                refreshToken: $tokenResponse->refreshToken,
                idToken: $tokenResponse->idToken,
                refreshExpiresIn: $tokenResponse->refreshExpiresIn,
            );

            $this->logger->info('auth.callback.success', [
                'correlation_id' => $correlationId,
                'user_email' => $identityEmail,
                'session_id_hash' => hash('sha256', $createdSession->sessionId),
            ]);

            return new CallbackRedirectData(
                sessionId: $createdSession->sessionId,
                sessionTtlSeconds: $createdSession->ttlSeconds,
                redirectUrl: (string) config('keycloak.frontend_dashboard_url'),
            );
        } catch (InvalidStateException|TokenExchangeException|SessionPersistenceException $exception) {
            $this->logCallbackFailure($correlationId, $identityEmail, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logCallbackFailure($correlationId, $identityEmail, $exception);

            throw $exception;
        }
    }

    private function assertValidState(string $state, ?string $stateCookie, mixed $issuer): void
    {
        if (! is_string($stateCookie) || $stateCookie === '') {
            throw new InvalidStateException('Missing auth callback state cookie.');
        }

        if (! hash_equals($stateCookie, $state)) {
            throw new InvalidStateException('Auth callback state mismatch.');
        }

        if (is_string($issuer) && $issuer !== '' && $issuer !== (string) config('keycloak.issuer')) {
            throw new InvalidStateException('Auth callback issuer mismatch.');
        }
    }

    /**
     * @return array{sub: string, email: string}
     */
    private function extractIdentityFromIdToken(TokenExchangeResponse $tokenResponse): array
    {
        $parts = explode('.', $tokenResponse->idToken);

        if (count($parts) !== 3) {
            throw new TokenExchangeException('Invalid ID token format.');
        }

        $payload = $this->decodeJwtPayload($parts[1]);
        $subject = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;

        if (! is_string($subject) || $subject === '') {
            throw new TokenExchangeException('Missing subject claim in ID token.');
        }

        if (! is_string($email) || trim($email) === '') {
            throw new TokenExchangeException('Missing email claim in ID token.');
        }

        return [
            'sub' => $subject,
            'email' => strtolower(trim($email)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $payload): array
    {
        $remainder = strlen($payload) % 4;
        $normalizedPayload = strtr($payload, '-_', '+/');

        if ($remainder > 0) {
            $normalizedPayload .= str_repeat('=', 4 - $remainder);
        }

        $decodedPayload = base64_decode($normalizedPayload, true);

        if ($decodedPayload === false) {
            throw new TokenExchangeException('Unable to decode ID token payload.');
        }

        try {
            $claims = json_decode($decodedPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new TokenExchangeException('Unable to parse ID token payload.', 0, $exception);
        }

        if (! is_array($claims)) {
            throw new TokenExchangeException('Invalid ID token payload.');
        }

        return $claims;
    }

    private function logCallbackFailure(string $correlationId, ?string $identityEmail, Throwable $exception): void
    {
        $context = [
            'correlation_id' => $correlationId,
            'user_email' => $identityEmail,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof InvalidStateException) {
            $this->logger->warning('auth.callback.fail', $context);

            return;
        }

        $this->logger->error('auth.callback.fail', $context);
    }
}