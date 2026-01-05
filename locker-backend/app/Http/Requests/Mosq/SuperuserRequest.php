<?php

declare(strict_types=1);

namespace App\Http\Requests\Mosq;

use Illuminate\Foundation\Http\FormRequest;

class SuperuserRequest extends FormRequest
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
            'username' => ['required', 'string'], // TODO: checken ob es wirklich reicht nur den username zu überprüfen
        ];
    }
}
