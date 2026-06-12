<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RuntimeProtectionMiddleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($response = $this->deploymentSkewResponse($request)) {
            return $response;
        }

        if ($response = $this->proxyMismatchResponse($request)) {
            return $response;
        }

        if ($response = $this->corsDeniedResponse($request)) {
            return $response;
        }

        return $next($request);
    }

    private function deploymentSkewResponse(Request $request): ?JsonResponse
    {
        $frontendDeploymentId = $this->normalize($request->header('X-Deployment-ID'));
        $frontendContractVersion = $this->normalize($request->header('X-Contract-Version'));
        $runtimeDeploymentId = $this->normalize(config('app.deployment_id'));
        $runtimeContractVersion = $this->normalize(config('app.contract_version'));

        $deploymentMismatch = $frontendDeploymentId !== null
            && $runtimeDeploymentId !== null
            && $frontendDeploymentId !== $runtimeDeploymentId;
        $contractMismatch = $frontendContractVersion !== null
            && $runtimeContractVersion !== null
            && $frontendContractVersion !== $runtimeContractVersion;

        if (! $deploymentMismatch && ! $contractMismatch) {
            return null;
        }

        Log::warning('be.runtime.skew_detected', [
            'frontend_deployment_id' => $frontendDeploymentId,
            'frontend_contract_version' => $frontendContractVersion,
            'deployment_mismatch' => $deploymentMismatch,
            'contract_mismatch' => $contractMismatch,
        ]);

        return ApiResponse::error(new ErrorData(
            code: 'runtime_skew',
            message: 'The client runtime is out of date. Reload the application and try again.',
            reason: 'DEPLOYMENT_SKEW',
            failureCode: 'BE_DEPLOYMENT_SKEW',
            recoveryHint: 'reload_runtime',
            retryable: true,
            runtimeBoundary: 'backend_runtime',
        ), 412);
    }

    private function proxyMismatchResponse(Request $request): ?JsonResponse
    {
        $forwardedProto = $this->normalize($request->header('X-Forwarded-Proto'));

        if ($forwardedProto === null) {
            return null;
        }

        if (! in_array($forwardedProto, ['http', 'https'], true)) {
            return $this->proxyMismatchError($forwardedProto, $request->getScheme());
        }

        if ($request->getScheme() !== $forwardedProto) {
            return $this->proxyMismatchError($forwardedProto, $request->getScheme());
        }

        return null;
    }

    private function corsDeniedResponse(Request $request): ?JsonResponse
    {
        if (! $request->is('v1/*') && ! $request->is('api/v1/*')) {
            return null;
        }

        $origin = $this->normalize($request->header('Origin'));

        if ($origin === null) {
            return null;
        }

        $allowedOrigins = config('cors.allowed_origins', []);

        if (! is_array($allowedOrigins) || in_array('*', $allowedOrigins, true)) {
            return null;
        }

        $normalizedOrigins = array_values(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalize($value),
            $allowedOrigins,
        )));

        if (in_array($origin, $normalizedOrigins, true)) {
            return null;
        }

        Log::warning('be.runtime.cors_denied', [
            'origin' => $origin,
            'path' => '/'.$request->path(),
        ]);

        return ApiResponse::error(new ErrorData(
            code: 'forbidden',
            message: 'This browser origin is not allowed for the runtime.',
            reason: 'CORS_DENIED',
            failureCode: 'BE_CORS_DENIED',
            recoveryHint: 'verify_origin',
            retryable: false,
            runtimeBoundary: 'backend_runtime',
        ), 403);
    }

    private function proxyMismatchError(string $forwardedProto, string $resolvedScheme): JsonResponse
    {
        Log::warning('be.runtime.proxy_mismatch', [
            'forwarded_proto' => $forwardedProto,
            'resolved_scheme' => $resolvedScheme,
        ]);

        return ApiResponse::error(new ErrorData(
            code: 'proxy_mismatch',
            message: 'The upstream proxy metadata is inconsistent with the backend runtime.',
            reason: 'PROXY_MISMATCH',
            failureCode: 'BE_PROXY_MISMATCH',
            recoveryHint: 'retry_later',
            retryable: true,
            runtimeBoundary: 'backend_runtime',
        ), 502);
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : strtolower($normalized);
    }
}