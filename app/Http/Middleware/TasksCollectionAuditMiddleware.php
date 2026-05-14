<?php

namespace App\Http\Middleware;

use App\Domain\ClientPortal\Enums\ProjectDetailResolution;
use App\Services\Session\SessionData;
use App\Support\Api\Query\ListQuery;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class TasksCollectionAuditMiddleware
{
    public const QUERY_ATTRIBUTE = 'tasks.collection.query';

    public const RESULT_COUNT_ATTRIBUTE = 'tasks.collection.result_count';

    public const TOTAL_ATTRIBUTE = 'tasks.collection.total';

    public const RESOLUTION_ATTRIBUTE = 'tasks.collection.resolution';

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
        $projectId = $request->route('projectId');

        $this->logger->info('tasks.collection.request', [
            'correlation_id' => $correlationId,
            'method' => $request->method(),
            'path' => $request->path(),
            'project_id' => is_scalar($projectId) ? (string) $projectId : null,
            'query_params' => $queryParams,
            'session_cookie_present' => is_string($sessionCookie) && $sessionCookie !== '',
        ]);

        $response = $next($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);
        $normalizedQuery = $request->attributes->get(self::QUERY_ATTRIBUTE);
        $resultCount = $request->attributes->get(self::RESULT_COUNT_ATTRIBUTE);
        $total = $request->attributes->get(self::TOTAL_ATTRIBUTE);
        $resolution = $request->attributes->get(self::RESOLUTION_ATTRIBUTE);
        $resolutionValue = $resolution instanceof ProjectDetailResolution ? $resolution->value : null;

        if ($response->getStatusCode() === 401) {
            $this->logger->warning('tasks.collection.unauthorized', [
                'correlation_id' => $correlationId,
                'method' => $request->method(),
                'path' => $request->path(),
                'project_id' => is_scalar($projectId) ? (string) $projectId : null,
                'query_params' => $queryParams,
                'session_cookie_present' => is_string($sessionCookie) && $sessionCookie !== '',
            ]);

            return $response;
        }

        if ($response->getStatusCode() === 404) {
            $this->logger->warning('tasks.collection.not_found', [
                'correlation_id' => $correlationId,
                'project_id' => is_scalar($projectId) ? (string) $projectId : null,
                'user_id' => $session instanceof SessionData ? $session->userSub : null,
                'ownership_result' => $resolutionValue,
                'status' => $response->getStatusCode(),
            ]);

            return $response;
        }

        if ($response->isSuccessful() && $session instanceof SessionData) {
            $this->logger->info('tasks.collection.success', [
                'correlation_id' => $correlationId,
                'project_id' => is_scalar($projectId) ? (string) $projectId : null,
                'user_id' => $session->userSub,
                'user_email' => $session->userEmail,
                'ownership_result' => $resolutionValue,
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