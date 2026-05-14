<?php

$baseUrl = rtrim((string) env('KEYCLOAK_BASE_URL', 'https://sso.skill-wanderer.com'), '/');
$realm = (string) env('KEYCLOAK_REALM', 'client-portal');

return [
    'base_url' => $baseUrl,
    'realm' => $realm,
    'issuer' => env('KEYCLOAK_ISSUER', $baseUrl.'/realms/'.$realm),
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'client-portal-fe'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET', ''),
    'redirect_uri' => env('KEYCLOAK_REDIRECT_URI', 'https://api.skill-wanderer.com/v1/auth/callback'),
    'authorization_endpoint' => env(
        'KEYCLOAK_AUTHORIZATION_ENDPOINT',
        $baseUrl.'/realms/'.$realm.'/protocol/openid-connect/auth'
    ),
    'token_endpoint' => env(
        'KEYCLOAK_TOKEN_ENDPOINT',
        $baseUrl.'/realms/'.$realm.'/protocol/openid-connect/token'
    ),
    'frontend_dashboard_url' => env(
        'KEYCLOAK_FRONTEND_DASHBOARD_URL',
        'https://client.skill-wanderer.com/dashboard'
    ),
    'timeout' => (int) env('KEYCLOAK_TIMEOUT', 10),
    'response_type' => 'code',
    'scope' => 'openid profile email',
];