<?php

namespace App\Http\Middleware;

use App\Services\Session\Exceptions\SessionRetrievalException;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SessionMiddleware
{
    public const REQUEST_ATTRIBUTE = 'auth.session';

    public const CORRELATION_ID_ATTRIBUTE = 'correlation_id';

    public function __construct(
        private readonly SessionService $sessionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolveCorrelationId($request);
        $request->attributes->set(self::CORRELATION_ID_ATTRIBUTE, $correlationId);

        $sessionId = $request->cookie('__session');

        if (! is_string($sessionId) || $sessionId === '') {
            return $this->unauthorizedResponse($correlationId, 'NO_SESSION');
        }

        try {
            $session = $this->sessionService->getSession($sessionId);
        } catch (SessionRetrievalException $exception) {
            $this->logger->error('auth.me.fail', [
                'correlation_id' => $correlationId,
                'user_email' => null,
                'reason' => 'INTERNAL_ERROR',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->internalErrorResponse($correlationId);
        }

        if (! $session instanceof SessionData) {
            $this->bestEffortDelete($sessionId, $correlationId);

            return $this->unauthorizedResponse($correlationId, 'NO_SESSION');
        }

        if ($session->isExpired()) {
            $this->bestEffortDelete($sessionId, $correlationId, $session->userEmail);

            return $this->unauthorizedResponse($correlationId, 'SESSION_EXPIRED', $session->userEmail);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $session);

        return $next($request);
    }

    private function unauthorizedResponse(
        string $correlationId,
        string $reason,
        ?string $userEmail = null,
    ): JsonResponse {
        $this->logger->warning('auth.me.fail', [
            'correlation_id' => $correlationId,
            'user_email' => $userEmail,
            'reason' => $reason,
        ]);

        $response = ApiResponse::error([
            'code' => 'unauthorized',
            'message' => 'An authenticated session is required.',
            'reason' => $reason,
            'authenticated' => false,
        ], 401, $correlationId);
        $response->withCookie($this->expireSessionCookie());

        return $response;
    }

    private function internalErrorResponse(string $correlationId): JsonResponse
    {
        return ApiResponse::error([
            'code' => 'internal_error',
            'message' => 'The auth session could not be resolved.',
            'reason' => 'INTERNAL_ERROR',
            'authenticated' => false,
        ], 500, $correlationId);
    }

    private function decorateResponse(JsonResponse $response, string $correlationId): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Correlation-ID', $correlationId);
    }

    private function expireSessionCookie(): Cookie
    {
        return Cookie::create(
            name: '__session',
            value: '',
            expire: now()->subMinute(),
            path: '/',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function resolveCorrelationId(Request $request): string
    {
        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : (string) Str::uuid();
    }

    private function bestEffortDelete(
        string $sessionId,
        string $correlationId,
        ?string $userEmail = null,
    ): void
    {
        try {
            $this->sessionService->deleteSession($sessionId);
        } catch (SessionRetrievalException $exception) {
            $this->logger->warning('auth.me.fail', [
                'correlation_id' => $correlationId,
                'user_email' => $userEmail,
                'reason' => 'SESSION_DELETE_FAILED',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}