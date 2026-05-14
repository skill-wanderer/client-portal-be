<?php

namespace App\Infrastructure\Keycloak;

use App\Services\Auth\Exceptions\TokenExchangeException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class KeycloakClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function buildAuthorizationUrl(string $state): string
    {
        $endpoint = rtrim((string) config('keycloak.authorization_endpoint'), '?');

        $query = http_build_query([
            'client_id' => config('keycloak.client_id'),
            'redirect_uri' => config('keycloak.redirect_uri'),
            'response_type' => config('keycloak.response_type', 'code'),
            'scope' => config('keycloak.scope', 'openid profile email'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.'?'.$query;
    }

    public function exchangeCode(string $code): TokenExchangeResponse
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => config('keycloak.client_id'),
            'code' => $code,
            'redirect_uri' => config('keycloak.redirect_uri'),
        ];

        $clientSecret = (string) config('keycloak.client_secret', '');

        if ($clientSecret !== '') {
            $payload['client_secret'] = $clientSecret;
        }

        try {
            $request = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout((int) config('keycloak.timeout', 10));

            if (app()->isLocal()) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post((string) config('keycloak.token_endpoint'), $payload);
        } catch (Throwable $exception) {
            throw new TokenExchangeException('Keycloak token exchange request failed.', 0, $exception);
        }

        if ($response->failed()) {
            throw new TokenExchangeException('Keycloak token exchange failed.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new TokenExchangeException('Invalid token exchange response payload.');
        }

        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;
        $idToken = $data['id_token'] ?? null;
        $refreshExpiresIn = $data['refresh_expires_in'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new TokenExchangeException('Missing access token from token exchange response.');
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new TokenExchangeException('Missing refresh token from token exchange response.');
        }

        if (! is_string($idToken) || $idToken === '') {
            throw new TokenExchangeException('Missing ID token from token exchange response.');
        }

        if (! is_numeric($refreshExpiresIn)) {
            throw new TokenExchangeException('Missing refresh expiry from token exchange response.');
        }

        return new TokenExchangeResponse(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            idToken: $idToken,
            refreshExpiresIn: max(1, (int) $refreshExpiresIn),
        );
    }
}