<?php

declare(strict_types=1);

namespace App\Http\Requests\Vmq;

use Illuminate\Foundation\Http\FormRequest;

class AuthOnRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mountpoint' => ['nullable', 'string'],
            'client_id' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
        ];
    }
}
