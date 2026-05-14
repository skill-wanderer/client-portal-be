<?php

namespace App\Http\Middleware;

use App\Services\Session\SessionData;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class RBACMiddleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string $requiredRole): Response
    {
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);
        $correlationId = $this->resolveCorrelationId($request);

        if (! $session instanceof SessionData) {
            return $this->unauthorizedResponse($correlationId);
        }

        if ($requiredRole === '' || $session->userRole === '' || $session->userRole !== strtolower($requiredRole)) {
            $this->logger->warning('auth.rbac.denied', [
                'correlation_id' => $correlationId,
                'user_id' => $session->userSub,
                'role' => $session->userRole,
                'required_role' => strtolower($requiredRole),
                'resource_id' => null,
            ]);

            return $this->forbiddenResponse($correlationId);
        }

        return $next($request);
    }

    private function unauthorizedResponse(string $correlationId): JsonResponse
    {
        return ApiResponse::error([
            'code' => 'unauthorized',
            'message' => 'An authenticated session is required.',
            'reason' => 'NO_SESSION',
        ], 401, $correlationId);
    }

    private function forbiddenResponse(string $correlationId): JsonResponse
    {
        return ApiResponse::error([
            'code' => 'forbidden',
            'message' => 'You are not authorized to access this resource.',
            'reason' => 'AUTH_RBAC_DENIED',
        ], 403, $correlationId);
    }

    private function resolveCorrelationId(Request $request): string
    {
        $correlationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        return is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : 'unknown';
    }
}