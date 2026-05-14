<?php

namespace App\Http\Middleware;

use App\Services\Session\SessionData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class OwnershipMiddleware
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);
        $correlationId = $this->resolveCorrelationId($request);

        if (! $session instanceof SessionData) {
            return $this->unauthorizedResponse($correlationId);
        }

        $resourceId = $request->route('userId');

        if (! is_scalar($resourceId) || (string) $resourceId === '' || (string) $resourceId !== $session->userSub) {
            $this->logger->warning('auth.ownership.denied', [
                'correlation_id' => $correlationId,
                'user_id' => $session->userSub,
                'role' => $session->userRole,
                'resource_id' => is_scalar($resourceId) ? (string) $resourceId : null,
            ]);

            return $this->notFoundResponse($correlationId);
        }

        return $next($request);
    }

    private function unauthorizedResponse(string $correlationId): JsonResponse
    {
        $response = response()->json([
            'success' => false,
            'reason' => 'NO_SESSION',
        ], 401);

        return $this->decorateResponse($response, $correlationId);
    }

    private function notFoundResponse(string $correlationId): JsonResponse
    {
        $response = response()->json([
            'success' => false,
            'reason' => 'AUTH_OWNERSHIP_DENIED',
        ], 404);

        return $this->decorateResponse($response, $correlationId);
    }

    private function decorateResponse(JsonResponse $response, string $correlationId): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $correlationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        return is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : 'unknown';
    }
}