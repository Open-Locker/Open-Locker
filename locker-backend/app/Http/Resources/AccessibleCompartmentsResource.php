<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Compartment;
use App\Models\LockerBank;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \Illuminate\Database\Eloquent\Collection<int, LockerBank> $resource
 */
#[SchemaName('AccessibleCompartments')]
class AccessibleCompartmentsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'locker_banks' => collect($this->resource)->map(
                static fn (LockerBank $lockerBank): array => [
                    'id' => (string) $lockerBank->id,
                    'name' => $lockerBank->name,
                    'location_description' => $lockerBank->location_description,
                    'last_compartment_state_change_at' => $lockerBank->last_compartment_state_change_at?->toIso8601String(),
                    'compartments' => $lockerBank->compartments->map(
                        static fn (Compartment $compartment): array => [
                            'id' => (string) $compartment->id,
                            'number' => (int) $compartment->number,
                            'door_state' => $compartment->door_state->value,
                            'door_state_changed_at' => $compartment->door_state_changed_at?->toIso8601String(),
                            'content_note' => $compartment->content_note,
                            'content_note_updated_at' => $compartment->content_note_updated_at?->toIso8601String(),
                            'content_note_updated_by_user_id' => $compartment->content_note_updated_by_user_id,
                        ]
                    )->values()->all(),
                ]
            )->values()->all(),
        ];
    }
}
