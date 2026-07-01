<?php

namespace App\Http\Middleware;

use App\Security\Keycloak\KeycloakPrincipal;
use App\Services\Session\SessionData;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class DashboardAuditMiddleware
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
        $correlationId = $this->resolveCorrelationId($request);

        $this->logger->info('dashboard.request', [
            'correlation_id' => $correlationId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);
        $principal = $request->attributes->get(EnsureKeycloakBearerToken::REQUEST_ATTRIBUTE);

        if ($response->getStatusCode() === 401) {
            $this->logger->warning('dashboard.unauthorized', [
                'correlation_id' => $correlationId,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);

            return $response;
        }

        if ($response->isSuccessful() && $session instanceof SessionData) {
            $this->logger->info('dashboard.success', [
                'correlation_id' => $correlationId,
                'user_id' => $session->userSub,
                'user_email' => $session->userEmail,
                'status' => $response->getStatusCode(),
            ]);
        } elseif ($response->isSuccessful() && $principal instanceof KeycloakPrincipal) {
            $this->logger->info('dashboard.success', [
                'correlation_id' => $correlationId,
                'user_id' => $principal->sub,
                'user_email' => $principal->email ?? $principal->preferredUsername ?? $principal->sub,
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $attributeCorrelationId = $request->attributes->get(RequestContextMiddleware::CORRELATION_ID_ATTRIBUTE)
            ?? $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : 'unknown';
    }
}
