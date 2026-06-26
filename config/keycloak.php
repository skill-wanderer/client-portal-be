<?php

$baseUrl = rtrim((string) env('KEYCLOAK_BASE_URL', 'https://sso.skill-wanderer.com'), '/');
$realm = (string) env('KEYCLOAK_REALM', 'client-portal');
$backendUrl = rtrim((string) env('APP_URL', ''), '/');
$frontendUrl = rtrim((string) env('FRONTEND_APP_URL', ''), '/');
$csv = static fn (string $value): array => array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', $value),
), static fn (string $item): bool => $item !== ''));

return [
    'base_url' => $baseUrl,
    'realm' => $realm,
    'issuer' => env('KEYCLOAK_ISSUER', $baseUrl.'/realms/'.$realm),
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'client-portal-fe'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET', ''),
    'redirect_uri' => $backendUrl !== '' ? $backendUrl.'/v1/auth/callback' : '',
    'authorization_endpoint' => env(
        'KEYCLOAK_AUTHORIZATION_ENDPOINT',
        $baseUrl.'/realms/'.$realm.'/protocol/openid-connect/auth'
    ),
    'token_endpoint' => env(
        'KEYCLOAK_TOKEN_ENDPOINT',
        $baseUrl.'/realms/'.$realm.'/protocol/openid-connect/token'
    ),
    'introspection_endpoint' => env(
        'KEYCLOAK_INTROSPECTION_ENDPOINT',
        $baseUrl.'/realms/'.$realm.'/protocol/openid-connect/token/introspect'
    ),
    'frontend_dashboard_url' => env(
        'KEYCLOAK_FRONTEND_DASHBOARD_URL',
        $frontendUrl !== '' ? $frontendUrl.'/dashboard' : ''
    ),
    'allowed_roles' => array_values(array_filter(array_map(
        static fn (string $role): string => strtolower(trim($role)),
        explode(',', (string) env('KEYCLOAK_ALLOWED_ROLES', 'client,admin'))
    ))),
    'allowed_realms' => $csv((string) env('KEYCLOAK_ALLOWED_REALMS', 'client-portal,skill-wanderer-admin')),
    'expected_audience' => env('KEYCLOAK_EXPECTED_AUDIENCE', 'client-portal-be'),
    'allowed_algorithms' => $csv((string) env('KEYCLOAK_ALLOWED_ALGORITHMS', 'RS256')),
    'admin_realm' => env('KEYCLOAK_ADMIN_REALM', 'skill-wanderer-admin'),
    'admin_required_realm_role' => env('KEYCLOAK_ADMIN_REQUIRED_REALM_ROLE', 'client'),
    'jwks_cache_ttl_seconds' => (int) env('KEYCLOAK_JWKS_CACHE_TTL_SECONDS', 3600),
    'clock_leeway_seconds' => (int) env('KEYCLOAK_CLOCK_LEEWAY_SECONDS', 60),
    'timeout' => (int) env('KEYCLOAK_TIMEOUT', 10),
    'response_type' => 'code',
    'scope' => 'openid profile email',
];
