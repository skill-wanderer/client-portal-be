<?php

namespace App\Http\Middleware;

use App\Services\Auth\ValidatedBearerToken;
use App\Services\Session\Exceptions\SessionRetrievalException;
use App\Services\Session\SessionData;
use App\Services\Session\SessionService;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
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

        $bearerToken = $request->attributes->get(BearerTokenMiddleware::REQUEST_ATTRIBUTE);

        if (! $bearerToken instanceof ValidatedBearerToken) {
            return $this->missingBearerResponse($correlationId);
        }

        $sessionId = $this->resolveSessionToken($request);

        if ($sessionId === null) {
            return $this->unauthorizedResponse($correlationId, 'NO_SESSION_ID');
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

        if (! $this->sessionMatchesBearer($session, $bearerToken)) {
            return $this->tokenSessionMismatchResponse($correlationId, $session, $bearerToken);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $session);

        return $next($request);
    }

    private function missingBearerResponse(string $correlationId): JsonResponse
    {
        return ApiResponse::error(new ErrorData(
            code: 'unauthorized',
            message: 'A validated bearer token is required.',
            reason: 'NO_BEARER_CONTEXT',
            authenticated: false,
            failureCode: 'BE_BEARER_TOKEN_INVALID',
            recoveryHint: 'reauthenticate',
            retryable: false,
            runtimeBoundary: 'backend_auth',
        ), 401, $correlationId);
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
            'failure_code' => 'BE_SESSION_EXPIRED',
            'recovery_hint' => 'reauthenticate',
            'retryable' => false,
            'runtime_boundary' => 'backend_session',
        ], 401, $correlationId);

        return $response;
    }

    private function tokenSessionMismatchResponse(
        string $correlationId,
        SessionData $session,
        ValidatedBearerToken $bearerToken,
    ): JsonResponse {
        $this->logger->warning('auth.session_mismatch', [
            'correlation_id' => $correlationId,
            'session_user_id' => $session->userSub,
            'bearer_subject' => $bearerToken->subject,
            'session_role' => $session->userRole,
            'bearer_role' => $bearerToken->role,
        ]);

        return ApiResponse::error(new ErrorData(
            code: 'forbidden',
            message: 'The bearer token does not match the authenticated session.',
            reason: 'TOKEN_SESSION_MISMATCH',
            authenticated: false,
            failureCode: 'BE_TOKEN_SESSION_MISMATCH',
            recoveryHint: 'reauthenticate',
            retryable: false,
            runtimeBoundary: 'backend_auth',
        ), 403, $correlationId);
    }

    private function internalErrorResponse(string $correlationId): JsonResponse
    {
        return ApiResponse::error(new ErrorData(
            code: 'internal_error',
            message: 'The auth session could not be resolved.',
            reason: 'INTERNAL_ERROR',
            authenticated: false,
            failureCode: 'BE_SESSION_LOOKUP_FAILED',
            recoveryHint: 'retry_auth_bootstrap',
            retryable: true,
            runtimeBoundary: 'backend_session',
        ), 500, $correlationId);
    }

    private function resolveCorrelationId(Request $request): string
    {
        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : (string) Str::uuid();
    }

    private function resolveSessionToken(Request $request): ?string
    {
        $sessionId = $request->header('X-Session-Id');

        return is_string($sessionId) && trim($sessionId) !== ''
            ? trim($sessionId)
            : null;
    }

    private function sessionMatchesBearer(SessionData $session, ValidatedBearerToken $bearerToken): bool
    {
        if ($session->userSub !== $bearerToken->subject) {
            return false;
        }

        if ($session->userRole !== '' && $bearerToken->role !== '' && $session->userRole !== $bearerToken->role) {
            return false;
        }

        return true;
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
