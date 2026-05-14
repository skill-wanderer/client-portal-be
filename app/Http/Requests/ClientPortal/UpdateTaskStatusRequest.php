<?php

namespace App\Http\Requests\ClientPortal;

use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use App\Domain\ClientPortal\Enums\TaskStatus;

class UpdateTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|Enum>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(TaskStatus::class)],
            'expectedVersion' => ['required', 'integer', 'min:1'],
            'idempotencyKey' => ['required', 'string', 'uuid'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $headerCorrelationId = $this->header('X-Correlation-ID');
        $correlationId = is_string($headerCorrelationId) && $headerCorrelationId !== ''
            ? $headerCorrelationId
            : (string) Str::uuid();

        throw new HttpResponseException(ApiResponse::error(
            new ErrorData(
                code: 'validation_error',
                message: 'The task status mutation request is invalid.',
                reason: 'VALIDATION_ERROR',
                details: ['fields' => $validator->errors()->toArray()],
            ),
            422,
            $correlationId,
        ));
    }
}