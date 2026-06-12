<?php

namespace App\Http\Controllers\Auth;

use App\Support\Api\ApiResponse;
use App\Support\Runtime\DeploymentRuntimeInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RuntimeController
{
    public function __construct(
        private readonly DeploymentRuntimeInspector $runtimeInspector,
    ) {
    }

    public function health(): JsonResponse
    {
        $report = $this->runtimeInspector->healthReport();

        return ApiResponse::success($report, $this->runtimeInspector->healthHttpStatus($report));
    }

    public function deployment(Request $request): JsonResponse
    {
        $report = $this->runtimeInspector->deploymentReport($request);

        return ApiResponse::success($report, $this->runtimeInspector->deploymentHttpStatus($report));
    }
}