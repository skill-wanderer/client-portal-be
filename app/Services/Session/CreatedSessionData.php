<?php

namespace App\Services\Session;

class CreatedSessionData
{
    public function __construct(
        public readonly string $sessionId,
        public readonly int $ttlSeconds,
    ) {
    }
}