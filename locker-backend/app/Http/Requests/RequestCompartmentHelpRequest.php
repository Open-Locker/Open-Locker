<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CompartmentService;
use Illuminate\Foundation\Http\FormRequest;

class RequestCompartmentHelpRequest extends FormRequest
{
    /**
     * Access is enforced in CompartmentService (active access or admin), so the
     * request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');
        if (is_string($message)) {
            $this->merge(['message' => trim($message)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.CompartmentService::HELP_MESSAGE_MAX_LENGTH],
        ];
    }
}
