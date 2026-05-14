<?php

namespace App\Services\Session;

use Carbon\CarbonImmutable;
use Throwable;

class SessionData
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userSub,
        public readonly string $userEmail,
        public readonly string $userRole,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(string $sessionId, array $payload): ?self
    {
        $user = $payload['user'] ?? null;
        $tokens = $payload['tokens'] ?? null;

        if (! is_array($user) || ! is_array($tokens)) {
            return null;
        }

        $subject = $user['sub'] ?? null;
        $email = $user['email'] ?? null;
        $role = array_key_exists('role', $user) ? $user['role'] : 'client';
        $expiresAt = $tokens['expiresAt'] ?? null;

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        if (! is_string($role)) {
            return null;
        }

        if (! is_string($expiresAt) || $expiresAt === '') {
            return null;
        }

        try {
            $parsedExpiresAt = CarbonImmutable::parse($expiresAt);
        } catch (Throwable) {
            return null;
        }

        return new self(
            sessionId: $sessionId,
            userSub: $subject,
            userEmail: strtolower(trim($email)),
            userRole: strtolower(trim($role)),
            expiresAt: $parsedExpiresAt,
        );
    }

    public function isExpired(?CarbonImmutable $currentTime = null): bool
    {
        return ($currentTime ?? CarbonImmutable::now())->greaterThan($this->expiresAt);
    }
}