<?php

namespace App\Infrastructure\Keycloak;

class TokenExchangeResponse
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly string $idToken,
        public readonly int $refreshExpiresIn,
    ) {
    }
}