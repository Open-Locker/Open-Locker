<?php

namespace App\Http\Resources;

use App\Models\Item;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @property Item $resource
 */
#[SchemaName('Item')]
class ItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activeLoan = $this->resource->activeLoan;

        $array = [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'image_url' => config('app.url').Storage::url($this->resource->image_path),
            'locker_id' => $this->resource->locker_id,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            /** @var CarbonImmutable | null */
            'borrowed_at' => $activeLoan?->borrowed_at,
        ];

        return $array;
    }
}
