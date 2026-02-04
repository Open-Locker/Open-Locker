<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
        $item = $this->resource->item;

        return [
            'id' => (string) $this->resource->id,
            'locker_bank_id' => (string) $this->resource->locker_bank_id,
            'number' => (int) $this->resource->number,
            'slave_id' => $this->resource->slave_id,
            'address' => $this->resource->address,
            'locker_bank' => $lockerBank ? [
                'id' => (string) $lockerBank->id,
                'name' => (string) $lockerBank->name,
                'location_description' => (string) $lockerBank->location_description,
            ] : null,
            'item' => $item ? new ItemResource($item) : null,
        ];
    }
}

