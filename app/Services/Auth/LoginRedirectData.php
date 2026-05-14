<?php

namespace App\Services\Auth;

class LoginRedirectData
{
    public function __construct(
        public readonly string $state,
        public readonly string $authorizationUrl,
        public readonly string $returnTo,
        public readonly bool $forceReauth,
    ) {
    }
}