<?php

declare(strict_types=1);

namespace App\Http\Requests\Vmq;

use Illuminate\Foundation\Http\FormRequest;

class AuthOnSubscribeRequest extends FormRequest
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
            'topics' => ['required', 'array'],
            'topics.*.topic' => ['required', 'string'],
            'topics.*.qos' => ['required', 'integer', 'between:0,2'],
        ];
    }
}
