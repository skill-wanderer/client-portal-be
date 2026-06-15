<?php

namespace App\Services\Auth;

use App\Infrastructure\Keycloak\KeycloakClient;
use App\Services\Auth\Exceptions\InvalidBearerTokenException;
use Carbon\CarbonImmutable;

class BearerTokenValidator
{
    public function __construct(
        private readonly KeycloakClient $keycloakClient,
    ) {
    }

    public function validate(string $token): ValidatedBearerToken
    {
        $normalizedToken = trim($token);

        if ($normalizedToken === '' || preg_match('/\s/', $normalizedToken) === 1) {
            throw new InvalidBearerTokenException('MALFORMED_BEARER_TOKEN', 'Malformed bearer token.');
        }

        $payload = $this->keycloakClient->introspectToken($normalizedToken);

        if (($payload['active'] ?? null) !== true) {
            throw new InvalidBearerTokenException('INACTIVE_BEARER_TOKEN', 'Inactive bearer token.');
        }

        $issuer = $this->stringClaim($payload, 'iss');
        $expectedIssuer = trim((string) config('keycloak.issuer'));

        if ($issuer === null || $expectedIssuer === '' || $issuer !== $expectedIssuer) {
            throw new InvalidBearerTokenException('INVALID_BEARER_TOKEN_ISSUER', 'Invalid bearer token issuer.');
        }

        $clientId = trim((string) config('keycloak.client_id'));

        if ($clientId === '' || ! $this->matchesConfiguredClient($payload, $clientId)) {
            throw new InvalidBearerTokenException('INVALID_BEARER_TOKEN_AUDIENCE', 'Invalid bearer token audience.');
        }

        $subject = $this->stringClaim($payload, 'sub');

        if ($subject === null) {
            throw new InvalidBearerTokenException('INVALID_BEARER_TOKEN_SUBJECT', 'Invalid bearer token subject.');
        }

        $email = $this->stringClaim($payload, 'email');

        if ($email === null) {
            throw new InvalidBearerTokenException('INVALID_BEARER_TOKEN_EMAIL', 'Invalid bearer token email.');
        }

        $expiresAt = $this->expiresAt($payload['exp'] ?? null);

        if ($expiresAt === null || CarbonImmutable::now()->greaterThanOrEqualTo($expiresAt)) {
            throw new InvalidBearerTokenException('EXPIRED_BEARER_TOKEN', 'Expired bearer token.');
        }

        $role = $this->resolveAllowedRole($payload, $clientId);

        if ($role === null) {
            throw new InvalidBearerTokenException('INVALID_BEARER_TOKEN_ROLE', 'Invalid bearer token role.');
        }

        return new ValidatedBearerToken(
            subject: $subject,
            email: strtolower($email),
            role: $role,
            expiresAt: $expiresAt,
            issuer: $issuer,
            audience: $this->audience($payload),
            authorizedParty: $this->stringClaim($payload, 'azp'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function matchesConfiguredClient(array $payload, string $clientId): bool
    {
        if (in_array($clientId, $this->audience($payload), true)) {
            return true;
        }

        foreach (['azp', 'client_id'] as $claim) {
            if ($this->stringClaim($payload, $claim) === $clientId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function audience(array $payload): array
    {
        $audience = $payload['aud'] ?? null;

        if (is_string($audience) && trim($audience) !== '') {
            return [trim($audience)];
        }

        if (! is_array($audience)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            $audience,
        )));
    }

    private function expiresAt(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAllowedRole(array $payload, string $clientId): ?string
    {
        $allowedRoles = $this->allowedRoles();

        foreach ($this->roles($payload, $clientId) as $role) {
            $normalizedRole = strtolower(trim($role));

            if (in_array($normalizedRole, $allowedRoles, true)) {
                return $normalizedRole;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function allowedRoles(): array
    {
        $configuredRoles = config('keycloak.allowed_roles', ['client', 'admin']);

        if (! is_array($configuredRoles)) {
            return ['client', 'admin'];
        }

        $roles = array_values(array_filter(array_map(
            static fn (mixed $role): ?string => is_string($role) && trim($role) !== ''
                ? strtolower(trim($role))
                : null,
            $configuredRoles,
        )));

        return $roles !== [] ? $roles : ['client', 'admin'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function roles(array $payload, string $clientId): array
    {
        $roles = [];
        $role = $this->stringClaim($payload, 'role');

        if ($role !== null) {
            $roles[] = $role;
        }

        $roles = array_merge($roles, $this->stringList($payload['roles'] ?? null));

        $realmAccess = $payload['realm_access'] ?? null;

        if (is_array($realmAccess)) {
            $roles = array_merge($roles, $this->stringList($realmAccess['roles'] ?? null));
        }

        $resourceAccess = $payload['resource_access'] ?? null;

        if (is_array($resourceAccess)) {
            $clientAccess = $resourceAccess[$clientId] ?? null;

            if (is_array($clientAccess)) {
                $roles = array_merge($roles, $this->stringList($clientAccess['roles'] ?? null));
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value,
        )));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringClaim(array $payload, string $claim): ?string
    {
        $value = $payload[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }
}
