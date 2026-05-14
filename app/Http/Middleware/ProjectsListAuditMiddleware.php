<?php

namespace App\Http\Middleware;

use App\Services\Session\SessionData;
use App\Support\Api\Query\ListQuery;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class ProjectsListAuditMiddleware
{
    public const QUERY_ATTRIBUTE = 'projects.list.query';

    public const RESULT_COUNT_ATTRIBUTE = 'projects.list.result_count';

    public const TOTAL_ATTRIBUTE = 'projects.list.total';

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
        $sessionCookie = $request->cookie('__session');
        $queryParams = $request->query();

        $this->logger->info('projects.list.request', [
            'correlation_id' => $correlationId,
            'method' => $request->method(),
            'path' => $request->path(),
            'query_params' => $queryParams,
            'session_cookie_present' => is_string($sessionCookie) && $sessionCookie !== '',
        ]);

        $response = $next($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if ($response->getStatusCode() === 401) {
            $this->logger->warning('projects.list.unauthorized', [
                'correlation_id' => $correlationId,
                'method' => $request->method(),
                'path' => $request->path(),
                'query_params' => $queryParams,
                'session_cookie_present' => is_string($sessionCookie) && $sessionCookie !== '',
            ]);

            return $response;
        }

        $normalizedQuery = $request->attributes->get(self::QUERY_ATTRIBUTE);
        $resultCount = $request->attributes->get(self::RESULT_COUNT_ATTRIBUTE);
        $total = $request->attributes->get(self::TOTAL_ATTRIBUTE);

        if ($response->isSuccessful() && $session instanceof SessionData) {
            $this->logger->info('projects.list.success', [
                'correlation_id' => $correlationId,
                'user_id' => $session->userSub,
                'user_email' => $session->userEmail,
                'path' => $request->path(),
                'query_params' => $queryParams,
                'normalized_query' => $normalizedQuery instanceof ListQuery
                    ? $normalizedQuery->toArray()
                    : null,
                'pagination' => [
                    'page' => $normalizedQuery instanceof ListQuery ? $normalizedQuery->page : null,
                    'per_page' => $normalizedQuery instanceof ListQuery ? $normalizedQuery->perPage : null,
                    'total' => is_int($total) ? $total : null,
                ],
                'result_count' => is_int($resultCount) ? $resultCount : null,
                'status' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $attributeCorrelationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : 'unknown';
    }
}