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




        $array = [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'image_url' => $this->resource->image_path ? config('app.url').Storage::url($this->resource->image_path) : null,
            'locker_id' => $this->resource->locker_id,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            /** @var ?Carbon | null */
            'borrowed_at' => $this->resource->activeLoan?->borrowed_at ? $this->resource->activeLoan->borrowed_at : null,
        ];


        return $array;
    }
}
