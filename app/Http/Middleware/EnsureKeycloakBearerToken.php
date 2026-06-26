<?php

namespace App\Http\Middleware;

use App\Security\Keycloak\Exceptions\InsufficientKeycloakRoleException;
use App\Security\Keycloak\Exceptions\InvalidKeycloakTokenException;
use App\Security\Keycloak\KeycloakTokenValidator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKeycloakBearerToken
{
    public const REQUEST_ATTRIBUTE = 'keycloak_principal';

    public function __construct(
        private readonly KeycloakTokenValidator $validator,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $principal = $this->validator->validateAuthorizationHeader($request->header('Authorization'));
        } catch (InsufficientKeycloakRoleException $exception) {
            return $this->forbiddenResponse();
        } catch (InvalidKeycloakTokenException $exception) {
            return $this->unauthenticatedResponse();
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $principal);

        return $next($request);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Forbidden.',
        ], 403);
    }
}
