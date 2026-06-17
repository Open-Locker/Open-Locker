<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CompartmentDoorState;
use App\Models\Compartment;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Compartment $resource
 */
#[SchemaName('Compartment')]
class CompartmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lockerBank = $this->resource->lockerBank;

        return [
            'id' => (string) $this->resource->id,
            'locker_bank_id' => (string) $this->resource->locker_bank_id,
            'number' => (int) $this->resource->number,
            'slave_id' => $this->resource->slave_id,
            'address' => $this->resource->address,
            'door_state' => $this->resource->door_state?->value ?? CompartmentDoorState::Unknown->value,
            'door_state_changed_at' => $this->resource->door_state_changed_at?->toIso8601String(),
            'locker_bank' => $lockerBank ? [
                'id' => (string) $lockerBank->id,
                'name' => (string) $lockerBank->name,
                'location_description' => (string) $lockerBank->location_description,
            ] : null,
        ];
    }
}
