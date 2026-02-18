<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LockerBank;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
                    'compartments' => $lockerBank->compartments->map(
                        static fn ($compartment): array => [
                            'id' => (string) $compartment->id,
                            'number' => (int) $compartment->number,
                            'item' => $compartment->item ? [
                                'id' => $compartment->item->id,
                                'name' => $compartment->item->name,
                                'description' => $compartment->item->description,
                            ] : null,
                        ]
                    )->values()->all(),
                ]
            )->values()->all(),
        ];
    }
}
