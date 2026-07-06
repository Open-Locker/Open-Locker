<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Compartment;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Compartment
 */
#[SchemaName('CompartmentContentNote')]
class CompartmentContentNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'compartment_id' => (string) $this->id,
            'content_note' => $this->content_note,
            'content_note_updated_at' => $this->content_note_updated_at?->toIso8601String(),
            'content_note_updated_by_user_id' => $this->content_note_updated_by_user_id,
        ];
    }
}
