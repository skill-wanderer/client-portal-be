<?php

namespace App\Http\Requests\ClientPortal;

use App\Domain\ClientPortal\Enums\TaskPriority;
use App\Support\Api\ApiResponse;
use App\Support\Api\Contracts\ErrorData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class CreateTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:2000'],
            'priority' => ['required', new Enum(TaskPriority::class)],
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
                message: 'The task creation request is invalid.',
                reason: 'VALIDATION_ERROR',
                details: ['fields' => $validator->errors()->toArray()],
            ),
            422,
            $correlationId,
        ));
    }
}