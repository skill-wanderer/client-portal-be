<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SessionMiddleware;
use App\Services\ClientPortal\ClientDashboardService;
use App\Services\Session\SessionData;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ClientPortal\DashboardData;
use App\Support\Api\Contracts\ErrorData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

class ClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardService $dashboardService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('dashboard.internal_error', [
                'correlation_id' => $correlationId,
                'reason' => 'MISSING_REQUEST_SESSION',
            ]);

            return ApiResponse::error(
                new ErrorData(
                    code: 'internal_error',
                    message: 'The dashboard session context is unavailable.',
                ),
                500,
                $correlationId,
            );
        }

        return ApiResponse::success(
            DashboardData::fromDomain($this->dashboardService->build($session)),
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