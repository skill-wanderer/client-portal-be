<?php

namespace App\Http\Middleware;

use App\Services\Auth\BearerTokenValidator;
use App\Services\Auth\Exceptions\InvalidBearerTokenException;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BearerTokenMiddleware
{
    public const REQUEST_ATTRIBUTE = 'auth.bearer';

    public function __construct(
        private readonly BearerTokenValidator $validator,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $validatedToken = $this->validator->validate($this->resolveBearerToken($request));
        } catch (InvalidBearerTokenException $exception) {
            return $this->unauthorizedResponse($exception);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $validatedToken);

        return $next($request);
    }

    private function resolveBearerToken(Request $request): string
    {
        $authorization = $request->header('Authorization');

        if (! is_string($authorization) || trim($authorization) === '') {
            throw new InvalidBearerTokenException('NO_BEARER_TOKEN', 'Missing bearer token.');
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            throw new InvalidBearerTokenException('MALFORMED_BEARER_TOKEN', 'Malformed bearer token.');
        }

        $token = trim($matches[1]);

        if ($token === '' || preg_match('/\s/', $token) === 1) {
            throw new InvalidBearerTokenException('MALFORMED_BEARER_TOKEN', 'Malformed bearer token.');
        }

        return $token;
    }

    private function unauthorizedResponse(InvalidBearerTokenException $exception): JsonResponse
    {
        return ApiResponse::error(new ErrorData(
            code: 'unauthorized',
            message: 'A valid bearer token is required.',
            reason: $exception->reason(),
            authenticated: false,
            failureCode: 'BE_BEARER_TOKEN_INVALID',
            recoveryHint: 'reauthenticate',
            retryable: false,
            runtimeBoundary: 'backend_auth',
        ), 401);
    }
}
