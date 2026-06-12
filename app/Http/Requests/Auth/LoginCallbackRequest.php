<?php

namespace App\Http\Requests\Auth;

use App\Http\Middleware\RequestContextMiddleware;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use App\Support\Security\AuthCookieSettings;
use App\Support\Security\AuthStateCookieData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class LoginCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'session_state' => ['nullable', 'string'],
            'iss' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $attributeCorrelationId = $this->attributes->get(RequestContextMiddleware::CORRELATION_ID_ATTRIBUTE);

        if (is_string($attributeCorrelationId) && $attributeCorrelationId !== '') {
            $resolvedCorrelationId = $attributeCorrelationId;
        } else {
            $correlationId = $this->header('X-Correlation-ID');

            if (is_string($correlationId) && $correlationId !== '') {
                $resolvedCorrelationId = $correlationId;
            } else {
                $stateCookieCorrelationId = AuthStateCookieData::decode($this->cookie('__state'))?->correlationId;
                $resolvedCorrelationId = is_string($stateCookieCorrelationId) && $stateCookieCorrelationId !== ''
                    ? $stateCookieCorrelationId
                    : (string) Str::uuid();
            }
        }

        $response = ApiResponse::error(new ErrorData(
            code: 'invalid_request',
            message: 'Missing required auth callback parameters.',
            reason: 'INVALID_REQUEST',
            details: [
                'errors' => $validator->errors()->toArray(),
            ],
            failureCode: 'BE_SESSION_EXPIRED',
            recoveryHint: 'restart_auth_flow',
            retryable: false,
            runtimeBoundary: 'backend_auth',
        ), 400, $resolvedCorrelationId);

        $response->withCookie(Cookie::create(
            name: '__state',
            value: '',
            expire: now()->subMinute(),
            path: AuthCookieSettings::path(),
            domain: AuthCookieSettings::domain(),
            secure: AuthCookieSettings::secure($this),
            httpOnly: true,
            raw: false,
            sameSite: AuthCookieSettings::sameSite(),
        ));

        throw new HttpResponseException($response);
    }
}