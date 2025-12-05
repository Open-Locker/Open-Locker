<?php

declare(strict_types=1);

namespace App\Http\Requests\Mosq;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'clientid' => ['nullable', 'string'],
        ];
    }
}
