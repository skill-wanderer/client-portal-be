<?php

namespace App\Http\Controllers\Api;

use App\Domain\ClientPortal\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProjectsDetailAuditMiddleware;
use App\Http\Middleware\ProjectsListAuditMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Services\ClientPortal\ClientProjectService;
use App\Services\Session\SessionData;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ClientPortal\ProjectCollectionData;
use App\Support\Api\Contracts\ClientPortal\ProjectDetailData;
use App\Support\Api\Contracts\ErrorData;
use App\Support\Api\Contracts\PaginationMeta;
use App\Support\Api\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

class ClientProjectController extends Controller
{
    public function __construct(
        private readonly ClientProjectService $projectService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('projects.list.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The projects session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        $query = ListQuery::fromRequest(
            request: $request,
            allowedSorts: ['updatedAt', 'createdAt', 'name'],
            defaultSort: 'updatedAt',
            defaultDirections: [
                'updatedAt' => 'desc',
                'createdAt' => 'desc',
                'name' => 'asc',
            ],
            allowedStatuses: array_map(
                static fn (ProjectStatus $status): string => $status->value,
                ProjectStatus::cases(),
            ),
        );

        $projection = $this->projectService->list($session, $query, $correlationId);
        $pagination = PaginationMeta::fromTotal($query->page, $query->perPage, $projection->total);

        $request->attributes->set(ProjectsListAuditMiddleware::QUERY_ATTRIBUTE, $query);
        $request->attributes->set(ProjectsListAuditMiddleware::RESULT_COUNT_ATTRIBUTE, count($projection->items));
        $request->attributes->set(ProjectsListAuditMiddleware::TOTAL_ATTRIBUTE, $projection->total);

        return ApiResponse::collection(
            ProjectCollectionData::fromDomain($projection, $pagination, $query),
            correlationId: $correlationId,
        );
    }

    public function show(Request $request, string $projectId): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('projects.detail.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
                'project_id' => $projectId,
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The project detail session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        $lookup = $this->projectService->detail($session, $projectId, $correlationId);
        $request->attributes->set(ProjectsDetailAuditMiddleware::RESOLUTION_ATTRIBUTE, $lookup->resolution);

        if ($lookup->project === null) {
            return ApiResponse::error(
                new ErrorData(
                    code: 'project_not_found',
                    message: 'The requested project could not be found.',
                    reason: 'PROJECT_NOT_FOUND',
                ),
                404,
                $correlationId,
            );
        }

        return ApiResponse::success(
            ProjectDetailData::fromDomain($lookup->project),
            correlationId: $correlationId,
        );
    }

    private function resolveCorrelationId(Request $request): ?string
    {
        $attributeCorrelationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : null;
    }
}