<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompartmentContentNoteRequest extends FormRequest
{
    /**
     * Access is enforced in CompartmentService (active access or admin), so the
     * request only validates the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the note and treat a blank value as "clear the note" (null).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('note')) {
            return;
        }

        $note = $this->input('note');
        if (is_string($note)) {
            $note = trim($note);
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['present', 'nullable', 'string', 'max:80'],
        ];
    }
}
