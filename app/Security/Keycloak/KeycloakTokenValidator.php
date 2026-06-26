<?php

namespace App\Security\Keycloak;

use App\Security\Keycloak\Exceptions\InsufficientKeycloakRoleException;
use App\Security\Keycloak\Exceptions\InvalidKeycloakTokenException;
use Firebase\JWT\JWT;
use JsonException;
use stdClass;
use Throwable;

class KeycloakTokenValidator
{
    public function __construct(
        private readonly KeycloakJwksProvider $jwksProvider,
    ) {
    }

    public function validateAuthorizationHeader(mixed $authorization): KeycloakPrincipal
    {
        if (! is_string($authorization) || trim($authorization) === '') {
            throw new InvalidKeycloakTokenException('NO_BEARER_TOKEN');
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            throw new InvalidKeycloakTokenException('MALFORMED_BEARER_TOKEN');
        }

        $token = trim($matches[1]);

        if ($token === '' || preg_match('/\s/', $token) === 1) {
            throw new InvalidKeycloakTokenException('MALFORMED_BEARER_TOKEN');
        }

        return $this->validateToken($token);
    }

    public function validateToken(string $token): KeycloakPrincipal
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidKeycloakTokenException('MALFORMED_BEARER_TOKEN');
        }

        $header = $this->decodeJwtPart($parts[0], 'header');
        $unverifiedClaims = $this->decodeJwtPart($parts[1], 'payload');
        $algorithm = $this->stringClaim($header, 'alg');

        if ($algorithm === null || ! in_array($algorithm, $this->allowedAlgorithms(), true)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_ALGORITHM');
        }

        $issuer = $this->stringClaim($unverifiedClaims, 'iss');
        $issuerMap = $this->allowedIssuerMap();

        if ($issuer === null || ! array_key_exists($issuer, $issuerMap)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_ISSUER');
        }

        $realm = $issuerMap[$issuer];
        $kid = $this->stringClaim($header, 'kid');

        if ($kid === null) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_KEY');
        }

        $keys = $this->jwksProvider->keysForRealm($realm);

        if (! array_key_exists($kid, $keys)) {
            $keys = $this->jwksProvider->keysForRealm($realm, forceRefresh: true);
        }

        if (! array_key_exists($kid, $keys)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_KEY');
        }

        $claims = $this->verifiedClaims($token, $keys[$kid]);
        $verifiedIssuer = $this->stringClaim($claims, 'iss');

        if ($verifiedIssuer !== $issuer) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_ISSUER');
        }

        if (! is_numeric($claims['exp'] ?? null)) {
            throw new InvalidKeycloakTokenException('EXPIRED_KEYCLOAK_TOKEN');
        }

        $audiences = $this->audiences($claims);
        $expectedAudience = trim((string) config('keycloak.expected_audience', 'client-portal-be'));

        if ($expectedAudience === '' || ! in_array($expectedAudience, $audiences, true)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_AUDIENCE');
        }

        $realmRoles = $this->realmRoles($claims);

        if ($realm === (string) config('keycloak.admin_realm', 'skill-wanderer-admin')) {
            $requiredRole = trim((string) config('keycloak.admin_required_realm_role', 'client'));

            if ($requiredRole === '' || ! in_array($requiredRole, $realmRoles, true)) {
                throw new InsufficientKeycloakRoleException;
            }
        }

        $subject = $this->stringClaim($claims, 'sub');

        if ($subject === null) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_SUBJECT');
        }

        return new KeycloakPrincipal(
            sub: $subject,
            issuer: $verifiedIssuer,
            realm: $realm,
            preferredUsername: $this->stringClaim($claims, 'preferred_username'),
            email: $this->stringClaim($claims, 'email'),
            name: $this->stringClaim($claims, 'name'),
            audiences: $audiences,
            realmRoles: $realmRoles,
            rawClaims: $claims,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $token, mixed $key): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = max(0, (int) config('keycloak.clock_leeway_seconds', 60));

        try {
            $payload = JWT::decode($token, $key);
        } catch (Throwable $exception) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN');
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        return $this->objectToArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPart(string $part, string $name): array
    {
        $decoded = base64_decode($this->base64UrlToBase64($part), true);

        if ($decoded === false) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_'.strtoupper($name));
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_'.strtoupper($name));
        }

        if (! is_array($payload)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_'.strtoupper($name));
        }

        return $payload;
    }

    private function base64UrlToBase64(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;

        if ($padding !== 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return $base64;
    }

    /**
     * @return array<string, string>
     */
    private function allowedIssuerMap(): array
    {
        $baseUrl = rtrim((string) config('keycloak.base_url', 'https://sso.skill-wanderer.com'), '/');
        $map = [];

        foreach ($this->allowedRealms() as $realm) {
            $map[$baseUrl.'/realms/'.$realm] = $realm;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function allowedRealms(): array
    {
        $realms = config('keycloak.allowed_realms', ['client-portal', 'skill-wanderer-admin']);

        if (! is_array($realms)) {
            return ['client-portal', 'skill-wanderer-admin'];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $realm): ?string => is_string($realm) && trim($realm) !== '' ? trim($realm) : null,
            $realms,
        )));
    }

    /**
     * @return array<int, string>
     */
    private function allowedAlgorithms(): array
    {
        $algorithms = config('keycloak.allowed_algorithms', ['RS256']);

        if (! is_array($algorithms)) {
            return ['RS256'];
        }

        $normalizedAlgorithms = array_values(array_filter(array_map(
            static fn (mixed $algorithm): ?string => is_string($algorithm) && trim($algorithm) !== '' ? trim($algorithm) : null,
            $algorithms,
        )));

        return $normalizedAlgorithms !== [] ? $normalizedAlgorithms : ['RS256'];
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<int, string>
     */
    private function audiences(array $claims): array
    {
        $audience = $claims['aud'] ?? null;

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

    /**
     * @param array<string, mixed> $claims
     * @return array<int, string>
     */
    private function realmRoles(array $claims): array
    {
        $realmAccess = $claims['realm_access'] ?? null;

        if (! is_array($realmAccess)) {
            return [];
        }

        $roles = $realmAccess['roles'] ?? null;

        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $role): ?string => is_string($role) && trim($role) !== '' ? trim($role) : null,
            $roles,
        )));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function stringClaim(array $claims, string $claim): ?string
    {
        $value = $claims[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectToArray(stdClass $payload): array
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $claims = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_PAYLOAD');
        }

        if (! is_array($claims)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_TOKEN_PAYLOAD');
        }

        return $claims;
    }
}
