<?php

namespace App\Http\Middleware;

use App\Support\Security\AuthStateCookieData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContextMiddleware
{
    public const CORRELATION_ID_ATTRIBUTE = 'correlation_id';

    public const REQUEST_ID_ATTRIBUTE = 'request_id';

    public const AUTH_FLOW_ID_ATTRIBUTE = 'auth_flow_id';

    public const DEPLOYMENT_ID_ATTRIBUTE = 'deployment_id';

    public const CONTRACT_VERSION_ATTRIBUTE = 'contract_version';

    public const UPSTREAM_REQUEST_ID_ATTRIBUTE = 'upstream_request_id';

    public const FRONTEND_DEPLOYMENT_ID_ATTRIBUTE = 'frontend_deployment_id';

    public const FRONTEND_CONTRACT_VERSION_ATTRIBUTE = 'frontend_contract_version';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $correlationId = $this->resolveCorrelationId($request);
        $requestId = (string) Str::uuid();
        $authFlowId = $this->resolveAuthFlowId($request);
        $deploymentId = $this->runtimeDeploymentId();
        $contractVersion = $this->runtimeContractVersion();
        $upstreamRequestId = $this->normalizeHeaderValue($request->header('X-Request-ID'));
        $frontendDeploymentId = $this->normalizeHeaderValue($request->header('X-Deployment-ID'));
        $frontendContractVersion = $this->normalizeHeaderValue($request->header('X-Contract-Version'));

        $request->attributes->set(self::CORRELATION_ID_ATTRIBUTE, $correlationId);
        $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        $request->attributes->set(self::DEPLOYMENT_ID_ATTRIBUTE, $deploymentId);
        $request->attributes->set(self::CONTRACT_VERSION_ATTRIBUTE, $contractVersion);

        if ($authFlowId !== null) {
            $request->attributes->set(self::AUTH_FLOW_ID_ATTRIBUTE, $authFlowId);
        }

        if ($upstreamRequestId !== null) {
            $request->attributes->set(self::UPSTREAM_REQUEST_ID_ATTRIBUTE, $upstreamRequestId);
        }

        if ($frontendDeploymentId !== null) {
            $request->attributes->set(self::FRONTEND_DEPLOYMENT_ID_ATTRIBUTE, $frontendDeploymentId);
        }

        if ($frontendContractVersion !== null) {
            $request->attributes->set(self::FRONTEND_CONTRACT_VERSION_ATTRIBUTE, $frontendContractVersion);
        }

        $request->headers->set('X-Correlation-ID', $correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'auth_flow_id' => $authFlowId,
            'deployment_id' => $deploymentId,
            'contract_version' => $contractVersion,
            'upstream_request_id' => $upstreamRequestId,
            'frontend_deployment_id' => $frontendDeploymentId,
            'frontend_contract_version' => $frontendContractVersion,
        ]);

        Log::info('be.request.received', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'runtime_kind' => 'laravel_http',
            'frontend_origin' => $this->normalizeHeaderValue($request->header('Origin')),
        ]);

        $response = $next($request);

        Log::info('be.response.sent', [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Deployment-ID', $deploymentId);
        $response->headers->set('X-Contract-Version', $contractVersion);

        return $response;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $headerCorrelationId = $this->normalizeHeaderValue($request->header('X-Correlation-ID'));

        if ($headerCorrelationId !== null) {
            return $headerCorrelationId;
        }

        $queryCorrelationId = $this->normalizeQueryValue($request->query('cid'));

        if ($queryCorrelationId !== null) {
            return $queryCorrelationId;
        }

        $stateCookieCorrelationId = $this->stateCookieData($request)?->correlationId;

        if (is_string($stateCookieCorrelationId) && $stateCookieCorrelationId !== '') {
            return $stateCookieCorrelationId;
        }

        return (string) Str::uuid();
    }

    private function resolveAuthFlowId(Request $request): ?string
    {
        $queryAuthFlowId = $this->normalizeQueryValue($request->query('auth_flow_id'));

        if ($queryAuthFlowId !== null) {
            return $queryAuthFlowId;
        }

        $stateCookieAuthFlowId = $this->stateCookieData($request)?->authFlowId;

        if (is_string($stateCookieAuthFlowId) && $stateCookieAuthFlowId !== '') {
            return $stateCookieAuthFlowId;
        }

        return null;
    }

    private function runtimeDeploymentId(): string
    {
        $configuredDeploymentId = config('app.deployment_id');

        return is_string($configuredDeploymentId) && trim($configuredDeploymentId) !== ''
            ? trim($configuredDeploymentId)
            : 'unknown';
    }

    private function runtimeContractVersion(): string
    {
        $configuredContractVersion = config('app.contract_version');

        return is_string($configuredContractVersion) && trim($configuredContractVersion) !== ''
            ? trim($configuredContractVersion)
            : 'unknown';
    }

    private function normalizeHeaderValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeQueryValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function stateCookieData(Request $request): ?AuthStateCookieData
    {
        return AuthStateCookieData::decode($request->cookie('__state'));
    }
}