<?php

namespace App\Support\Api;

use App\Http\Middleware\RequestContextMiddleware;
use App\Support\Api\Contracts\ApiDataContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiResponse
{
    /**
     * @param array<string, mixed>|ApiDataContract $data
     */
    public static function success(
        array|ApiDataContract $data,
        int $status = 200,
        ?string $correlationId = null,
    ): JsonResponse {
        $runtimeMetadata = self::resolveRuntimeMetadata($correlationId);
        $response = response()->json([
            'success' => true,
            'data' => self::normalizePayload($data),
        ], $status);

        self::decorate($response, $runtimeMetadata);

        return $response;
    }

    /**
     * @param array<string, mixed>|ApiDataContract $error
     */
    public static function error(
        array|ApiDataContract $error,
        int $status,
        ?string $correlationId = null,
    ): JsonResponse {
        $runtimeMetadata = self::resolveRuntimeMetadata($correlationId);
        $response = response()->json([
            'success' => false,
            'data' => null,
            'error' => self::normalizeErrorPayload(self::normalizePayload($error), $status),
            'correlation_id' => $runtimeMetadata['correlation_id'],
            'request_id' => $runtimeMetadata['request_id'],
            'deployment_id' => $runtimeMetadata['deployment_id'],
            'contract_version' => $runtimeMetadata['contract_version'],
        ], $status);

        self::decorate($response, $runtimeMetadata);

        return $response;
    }

    public static function collection(
        ApiDataContract $data,
        int $status = 200,
        ?string $correlationId = null,
    ): JsonResponse {
        return self::success($data, $status, $correlationId);
    }

    /**
     * @param array<string, mixed>|ApiDataContract $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array|ApiDataContract $payload): array
    {
        return $payload instanceof ApiDataContract
            ? $payload->toArray()
            : $payload;
    }

    /**
     * @param array{correlation_id: ?string, request_id: ?string, deployment_id: ?string, contract_version: ?string} $runtimeMetadata
     */
    private static function decorate(JsonResponse $response, array $runtimeMetadata): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        if (is_string($runtimeMetadata['correlation_id']) && $runtimeMetadata['correlation_id'] !== '') {
            $response->headers->set('X-Correlation-ID', $runtimeMetadata['correlation_id']);
        }

        if (is_string($runtimeMetadata['request_id']) && $runtimeMetadata['request_id'] !== '') {
            $response->headers->set('X-Request-ID', $runtimeMetadata['request_id']);
        }

        if (is_string($runtimeMetadata['deployment_id']) && $runtimeMetadata['deployment_id'] !== '') {
            $response->headers->set('X-Deployment-ID', $runtimeMetadata['deployment_id']);
        }

        if (is_string($runtimeMetadata['contract_version']) && $runtimeMetadata['contract_version'] !== '') {
            $response->headers->set('X-Contract-Version', $runtimeMetadata['contract_version']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeErrorPayload(array $payload, int $status): array
    {
        $inferredFailureMetadata = self::inferFailureMetadata($payload, $status);

        foreach ($inferredFailureMetadata as $key => $value) {
            if (! array_key_exists($key, $payload) && $value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function inferFailureMetadata(array $payload, int $status): array
    {
        $code = self::stringValue($payload['code'] ?? null);
        $reason = self::stringValue($payload['reason'] ?? null);

        return match (true) {
            $code === 'unauthorized' && in_array($reason, ['NO_SESSION', 'SESSION_EXPIRED'], true) => [
                'failure_code' => 'BE_SESSION_EXPIRED',
                'recovery_hint' => 'reauthenticate',
                'retryable' => false,
                'runtime_boundary' => 'backend_session',
            ],
            $code === 'internal_error' && $reason === 'INTERNAL_ERROR' => [
                'failure_code' => 'BE_SESSION_LOOKUP_FAILED',
                'recovery_hint' => 'retry_auth_bootstrap',
                'retryable' => true,
                'runtime_boundary' => 'backend_session',
            ],
            $code === 'conflict' && $reason === 'STALE_WRITE' => [
                'failure_code' => 'BE_MUTATION_STALE_WRITE',
                'recovery_hint' => 'refresh_before_retry',
                'retryable' => false,
                'runtime_boundary' => 'backend_mutation',
            ],
            $code === 'conflict' && in_array($reason, ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_IN_PROGRESS'], true) => [
                'failure_code' => 'BE_IDEMPOTENCY_CONFLICT',
                'recovery_hint' => 'do_not_retry_unsafe_mutation',
                'retryable' => false,
                'runtime_boundary' => 'backend_mutation',
            ],
            $code === 'runtime_skew' || $reason === 'DEPLOYMENT_SKEW' || $status === 412 => [
                'failure_code' => 'BE_DEPLOYMENT_SKEW',
                'recovery_hint' => 'reload_runtime',
                'retryable' => true,
                'runtime_boundary' => 'backend_runtime',
            ],
            $code === 'token_exchange_failed' => [
                'failure_code' => 'BE_KEYCLOAK_UNAVAILABLE',
                'recovery_hint' => 'retry_auth_bootstrap',
                'retryable' => true,
                'runtime_boundary' => 'backend_auth',
            ],
            default => [
                'runtime_boundary' => 'backend_runtime',
            ],
        };
    }

    /**
     * @return array{correlation_id: ?string, request_id: ?string, deployment_id: ?string, contract_version: ?string}
     */
    private static function resolveRuntimeMetadata(?string $fallbackCorrelationId = null): array
    {
        $request = self::currentRequest();

        return [
            'correlation_id' => self::requestAttribute($request, RequestContextMiddleware::CORRELATION_ID_ATTRIBUTE)
                ?? self::requestHeader($request, 'X-Correlation-ID')
                ?? self::stringValue($fallbackCorrelationId),
            'request_id' => self::requestAttribute($request, RequestContextMiddleware::REQUEST_ID_ATTRIBUTE)
                ?? self::requestHeader($request, 'X-Request-ID'),
            'deployment_id' => self::requestAttribute($request, RequestContextMiddleware::DEPLOYMENT_ID_ATTRIBUTE)
                ?? self::stringValue(config('app.deployment_id')),
            'contract_version' => self::requestAttribute($request, RequestContextMiddleware::CONTRACT_VERSION_ATTRIBUTE)
                ?? self::stringValue(config('app.contract_version')),
        ];
    }

    private static function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    private static function requestAttribute(?Request $request, string $key): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        return self::stringValue($request->attributes->get($key));
    }

    private static function requestHeader(?Request $request, string $key): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        return self::stringValue($request->header($key));
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}