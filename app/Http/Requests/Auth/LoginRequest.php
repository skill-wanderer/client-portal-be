<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'return_to' => ['nullable', 'string', 'regex:/^\/(?!\/).*/'],
            'force_reauth' => ['nullable', 'boolean'],
        ];
    }
}