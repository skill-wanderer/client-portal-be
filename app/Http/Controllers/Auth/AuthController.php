<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequestContextMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Requests\Auth\LoginCallbackRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\Exceptions\InvalidStateException;
use App\Services\Auth\Exceptions\TokenExchangeException;
use App\Services\Auth\AuthService;
use App\Services\Session\SessionData;
use App\Services\Session\Exceptions\SessionPersistenceException;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use App\Support\Security\AuthCookieSettings;
use App\Support\Security\AuthStateCookieData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class AuthController extends Controller
{
    private const SESSION_COOKIE_NAME = '__session';

    private const STATE_COOKIE_NAME = '__state';

    public function __construct(
        private readonly AuthService $authService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $authFlowId = $this->resolveAuthFlowId($request);
        $loginRedirect = $this->authService->beginLogin(
            returnTo: $request->validated('return_to') ?? '/',
            forceReauth: $request->boolean('force_reauth'),
        );

        $response = redirect()->away($loginRedirect->authorizationUrl);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->withCookie($this->makeStateCookie(new AuthStateCookieData(
            state: $loginRedirect->state,
            correlationId: $correlationId,
            authFlowId: $authFlowId,
        )));

        return $response;
    }

    public function callback(LoginCallbackRequest $request): RedirectResponse|JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $stateCookie = AuthStateCookieData::decode($request->cookie(self::STATE_COOKIE_NAME));

        try {
            $callbackRedirect = $this->authService->completeLogin(
                code: (string) $request->validated('code'),
                state: (string) $request->validated('state'),
                stateCookie: $stateCookie?->state,
                issuer: $request->validated('iss'),
                correlationId: $correlationId,
            );

            $response = redirect()->away($callbackRedirect->redirectUrl);
            $this->decorateCallbackResponse($response, $correlationId);
            $response->withCookie($this->makeSessionCookie(
                $callbackRedirect->sessionId,
                $callbackRedirect->sessionTtlSeconds,
            ));
            $response->withCookie($this->expireStateCookie());

            return $response;
        } catch (InvalidStateException $exception) {
            return $this->callbackErrorResponse(
                status: 400,
                code: 'invalid_state',
                message: 'Invalid auth callback state.',
                correlationId: $correlationId,
            );
        } catch (TokenExchangeException $exception) {
            return $this->callbackErrorResponse(
                status: 502,
                code: 'token_exchange_failed',
                message: 'Unable to exchange the authorization code.',
                correlationId: $correlationId,
            );
        } catch (SessionPersistenceException $exception) {
            return $this->callbackErrorResponse(
                status: 500,
                code: 'session_persistence_failed',
                message: 'Unable to persist the auth session.',
                correlationId: $correlationId,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->callbackErrorResponse(
                status: 500,
                code: 'internal_error',
                message: 'Unable to complete the auth callback.',
                correlationId: $correlationId,
            );
        }
    }

    public function me(Request $request): JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $session = $request->attributes->get(SessionMiddleware::REQUEST_ATTRIBUTE);

        if (! $session instanceof SessionData) {
            $this->logger->error('auth.me.fail', [
                'correlation_id' => $correlationId,
                'user_email' => null,
                'reason' => 'INTERNAL_ERROR',
            ]);

            $response = response()->json([
                'success' => false,
                'authenticated' => false,
                'reason' => 'INTERNAL_ERROR',
            ], 500);

            $this->decorateCallbackResponse($response, $correlationId);

            return $response;
        }

        $this->logger->info('auth.me.success', [
            'correlation_id' => $correlationId,
            'user_email' => $session->userEmail,
        ]);

        $response = response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $session->userSub,
                    'email' => $session->userEmail,
                ],
            ],
        ]);

        $this->decorateCallbackResponse($response, $correlationId);

        return $response;
    }

    private function callbackErrorResponse(
        int $status,
        string $code,
        string $message,
        string $correlationId,
    ): JsonResponse {
        $response = ApiResponse::error(new ErrorData(
            code: $code,
            message: $message,
            failureCode: $this->callbackFailureCode($code),
            recoveryHint: $this->callbackRecoveryHint($code),
            retryable: $this->callbackRetryable($code),
            runtimeBoundary: 'backend_auth',
        ), $status, $correlationId);

        $this->decorateCallbackResponse($response, $correlationId);
        $response->withCookie($this->expireStateCookie());

        return $response;
    }

    private function decorateCallbackResponse(
        RedirectResponse|JsonResponse $response,
        string $correlationId,
    ): void {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Correlation-ID', $correlationId);
    }

    private function makeSessionCookie(string $sessionId, int $ttlSeconds): Cookie
    {
        return Cookie::create(
            name: self::SESSION_COOKIE_NAME,
            value: $sessionId,
            expire: now()->addSeconds(max(1, $ttlSeconds)),
            path: AuthCookieSettings::path(),
            domain: AuthCookieSettings::domain(),
            secure: AuthCookieSettings::secure(request()),
            httpOnly: true,
            raw: false,
            sameSite: AuthCookieSettings::sameSite(),
        );
    }

    private function makeStateCookie(AuthStateCookieData $state): Cookie
    {
        return Cookie::create(
            name: self::STATE_COOKIE_NAME,
            value: $state->encode(),
            expire: now()->addMinutes(5),
            path: AuthCookieSettings::path(),
            domain: AuthCookieSettings::domain(),
            secure: AuthCookieSettings::secure(request()),
            httpOnly: true,
            raw: false,
            sameSite: AuthCookieSettings::sameSite(),
        );
    }

    private function expireStateCookie(): Cookie
    {
        return Cookie::create(
            name: self::STATE_COOKIE_NAME,
            value: '',
            expire: now()->subMinute(),
            path: AuthCookieSettings::path(),
            domain: AuthCookieSettings::domain(),
            secure: AuthCookieSettings::secure(request()),
            httpOnly: true,
            raw: false,
            sameSite: AuthCookieSettings::sameSite(),
        );
    }

    private function resolveCorrelationId(Request $request): string
    {
        $attributeCorrelationId = $request->attributes->get(RequestContextMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $stateCookieCorrelationId = AuthStateCookieData::decode($request->cookie(self::STATE_COOKIE_NAME))?->correlationId;

        if (is_string($stateCookieCorrelationId) && $stateCookieCorrelationId !== '') {
            return $stateCookieCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : (string) Str::uuid();
    }

    private function resolveAuthFlowId(Request $request): string
    {
        $attributeAuthFlowId = $request->attributes->get(RequestContextMiddleware::AUTH_FLOW_ID_ATTRIBUTE);

        if (is_string($attributeAuthFlowId) && $attributeAuthFlowId !== '') {
            return $attributeAuthFlowId;
        }

        $queryAuthFlowId = $request->query('auth_flow_id');

        return is_string($queryAuthFlowId) && trim($queryAuthFlowId) !== ''
            ? trim($queryAuthFlowId)
            : (string) Str::uuid();
    }

    private function callbackFailureCode(string $code): string
    {
        return match ($code) {
            'token_exchange_failed' => 'BE_KEYCLOAK_UNAVAILABLE',
            'invalid_state' => 'BE_SESSION_EXPIRED',
            'session_persistence_failed', 'internal_error' => 'BE_SESSION_LOOKUP_FAILED',
            default => 'BE_SESSION_LOOKUP_FAILED',
        };
    }

    private function callbackRecoveryHint(string $code): string
    {
        return match ($code) {
            'token_exchange_failed' => 'retry_auth_bootstrap',
            'invalid_state' => 'restart_auth_flow',
            'session_persistence_failed' => 'retry_auth_bootstrap',
            default => 'retry_auth_bootstrap',
        };
    }

    private function callbackRetryable(string $code): bool
    {
        return match ($code) {
            'invalid_state' => false,
            'token_exchange_failed', 'session_persistence_failed', 'internal_error' => true,
            default => false,
        };
    }
}