<?php

namespace App\Services\Auth;

class CallbackRedirectData
{
    public function __construct(
        public readonly string $sessionId,
        public readonly int $sessionTtlSeconds,
        public readonly string $redirectUrl,
    ) {
    }
}