<?php

namespace App\Services\Session;

use App\Services\Session\Exceptions\SessionPersistenceException;
use App\Services\Session\Exceptions\SessionRetrievalException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

class SessionService
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function createSession(
        string $subject,
        string $email,
        string $accessToken,
        string $refreshToken,
        string $idToken,
        int $refreshExpiresIn,
    ): CreatedSessionData {
        try {
            $sessionId = bin2hex(random_bytes(32));
            $ttlSeconds = max(1, $refreshExpiresIn);
            $expiresAt = now()->addSeconds($ttlSeconds)->toIso8601String();
            $payload = json_encode([
                'user' => [
                    'sub' => $subject,
                    'email' => $email,
                ],
                'tokens' => [
                    'access' => $accessToken,
                    'refresh' => $refreshToken,
                    'id' => $idToken,
                    'expiresAt' => $expiresAt,
                ],
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            $result = $this->cache->put($this->cacheKey($sessionId), $payload, $ttlSeconds);
        } catch (Throwable $exception) {
            throw new SessionPersistenceException('Failed to persist auth session.', 0, $exception);
        }

        if ($result === false) {
            throw new SessionPersistenceException('Failed to persist auth session.');
        }

        return new CreatedSessionData(
            sessionId: $sessionId,
            ttlSeconds: $ttlSeconds,
        );
    }

    public function getSession(string $sessionId): ?SessionData
    {
        if ($sessionId === '') {
            return null;
        }

        try {
            $payload = $this->cache->get($this->cacheKey($sessionId));
        } catch (Throwable $exception) {
            throw new SessionRetrievalException('Failed to load auth session.', 0, $exception);
        }

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decodedPayload)) {
            return null;
        }

        return SessionData::fromPayload($sessionId, $decodedPayload);
    }

    public function deleteSession(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            $this->cache->forget($this->cacheKey($sessionId));
        } catch (Throwable $exception) {
            throw new SessionRetrievalException('Failed to delete auth session.', 0, $exception);
        }
    }

    private function cacheKey(string $sessionId): string
    {
        return 'session:'.$sessionId;
    }
}
