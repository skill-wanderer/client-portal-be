<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Requests\Auth\LoginCallbackRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\Exceptions\InvalidStateException;
use App\Services\Auth\Exceptions\TokenExchangeException;
use App\Services\Auth\AuthService;
use App\Services\Session\SessionData;
use App\Services\Session\Exceptions\SessionPersistenceException;
use App\Support\Security\AuthCookieSettings;
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
        $loginRedirect = $this->authService->beginLogin(
            returnTo: $request->validated('return_to') ?? '/',
            forceReauth: $request->boolean('force_reauth'),
        );

        $response = redirect()->away($loginRedirect->authorizationUrl);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->withCookie($this->makeStateCookie($loginRedirect->state));

        return $response;
    }

    public function callback(LoginCallbackRequest $request): RedirectResponse|JsonResponse
    {
        $correlationId = $this->resolveCorrelationId($request);
        $stateCookie = $request->cookie(self::STATE_COOKIE_NAME);

        try {
            $callbackRedirect = $this->authService->completeLogin(
                code: (string) $request->validated('code'),
                state: (string) $request->validated('state'),
                stateCookie: $stateCookie,
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
        $response = response()->json([
            'message' => $message,
            'code' => $code,
            'correlation_id' => $correlationId,
        ], $status);

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

    private function makeStateCookie(string $state): Cookie
    {
        return Cookie::create(
            name: self::STATE_COOKIE_NAME,
            value: $state,
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
        $attributeCorrelationId = $request->attributes->get(SessionMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            return $attributeCorrelationId;
        }

        $headerCorrelationId = $request->header('X-Correlation-ID');

        return is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : (string) Str::uuid();
    }
}