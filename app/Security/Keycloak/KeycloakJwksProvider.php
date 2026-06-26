<?php

namespace App\Security\Keycloak;

use App\Security\Keycloak\Exceptions\InvalidKeycloakTokenException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class KeycloakJwksProvider
{
    /**
     * @return array<string, Key>
     */
    public function keysForRealm(string $realm, bool $forceRefresh = false): array
    {
        $normalizedRealm = trim($realm);

        if ($normalizedRealm === '' || ! in_array($normalizedRealm, $this->allowedRealms(), true)) {
            throw new InvalidKeycloakTokenException('UNTRUSTED_KEYCLOAK_REALM');
        }

        $cacheKey = $this->cacheKey($normalizedRealm);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $jwks = Cache::remember(
            $cacheKey,
            $this->cacheTtlSeconds(),
            fn (): array => $this->fetchJwks($normalizedRealm),
        );

        try {
            return JWK::parseKeySet($jwks, $this->defaultAlgorithm());
        } catch (Throwable $exception) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_JWKS');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $realm): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('keycloak.timeout', 10))
                ->get($this->jwksUrl($realm));
        } catch (Throwable $exception) {
            throw new InvalidKeycloakTokenException('KEYCLOAK_JWKS_UNAVAILABLE');
        }

        if (! $response->successful()) {
            throw new InvalidKeycloakTokenException('KEYCLOAK_JWKS_UNAVAILABLE');
        }

        $jwks = $response->json();

        if (! is_array($jwks)) {
            throw new InvalidKeycloakTokenException('INVALID_KEYCLOAK_JWKS');
        }

        return $jwks;
    }

    private function jwksUrl(string $realm): string
    {
        return $this->baseUrl().'/realms/'.rawurlencode($realm).'/protocol/openid-connect/certs';
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('keycloak.base_url', 'https://sso.skill-wanderer.com'), '/');
    }

    private function cacheKey(string $realm): string
    {
        return 'keycloak:jwks:'.hash('sha256', $this->baseUrl().'|'.$realm);
    }

    private function cacheTtlSeconds(): int
    {
        return max(1, (int) config('keycloak.jwks_cache_ttl_seconds', 3600));
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

    private function defaultAlgorithm(): string
    {
        $algorithms = config('keycloak.allowed_algorithms', ['RS256']);

        if (! is_array($algorithms)) {
            return 'RS256';
        }

        foreach ($algorithms as $algorithm) {
            if (is_string($algorithm) && trim($algorithm) !== '') {
                return trim($algorithm);
            }
        }

        return 'RS256';
    }
}
