<?php

namespace App\Http\Requests\Auth;

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
        $correlationId = $this->header('X-Correlation-ID');
        $resolvedCorrelationId = is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : (string) Str::uuid();

        $response = response()->json([
            'message' => 'Missing required auth callback parameters.',
            'code' => 'invalid_request',
            'correlation_id' => $resolvedCorrelationId,
            'errors' => $validator->errors(),
        ], 400);

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Correlation-ID', $resolvedCorrelationId);
        $response->withCookie(Cookie::create(
            name: '__state',
            value: '',
            expire: now()->subMinute(),
            path: '/v1/auth/callback',
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        throw new HttpResponseException($response);
    }
}