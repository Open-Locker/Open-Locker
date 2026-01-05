<?php

declare(strict_types=1);

namespace App\Http\Requests\Mosq;

use Illuminate\Foundation\Http\FormRequest;

class AclRequest extends FormRequest
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
            'clientid' => ['required', 'string'],
            'topic' => ['required', 'string'],
            'acc' => ['required', 'integer'], // 1=subscribe, 2=publish
        ];
    }
}
