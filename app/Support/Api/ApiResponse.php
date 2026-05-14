<?php

namespace App\Support\Api;

use App\Support\Api\Contracts\ApiDataContract;
use Illuminate\Http\JsonResponse;

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
        $response = response()->json([
            'success' => true,
            'data' => self::normalizePayload($data),
        ], $status);

        self::decorate($response, $correlationId);

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
        $response = response()->json([
            'success' => false,
            'data' => null,
            'error' => self::normalizePayload($error),
        ], $status);

        self::decorate($response, $correlationId);

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

    private static function decorate(JsonResponse $response, ?string $correlationId): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        if (is_string($correlationId) && $correlationId !== '') {
            $response->headers->set('X-Correlation-ID', $correlationId);
        }
    }
}