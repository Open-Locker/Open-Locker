<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read  CarbonImmutable $created_at
 * @property-read  CarbonImmutable $updated_at
 *
 * @uses \Database\Factories\ItemFactory
 */
class Item extends Model
{
    /** @use HasFactory<\Database\Factories\ItemFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image_path',
        'compartment_id',
    ];

    /**
     * Get the assigned compartment for this item.
     *
     * @return HasOne<Compartment, Item>
     */
    public function compartment(): HasOne
    {
        return $this->hasOne(Compartment::class, 'id', 'compartment_id');
    }
}
