<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

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
     * Get all loans for this item
     *
     * @return HasMany<ItemLoan, Item>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(ItemLoan::class);
    }

    /**
     * The compartment this item belongs to.
     *
     * @return BelongsTo<Compartment, Item>
     */
    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class);
    }

    /**
     * Get the current active loan for this item
     *
     * @return HasOne<ItemLoan, Item>
     */
    public function activeLoan(): HasOne
    {
        return $this->hasOne(ItemLoan::class)->whereNull('returned_at');
    }

    /**
     * Get the current borrower of the item through the active loan
     *
     * @return HasOneThrough<User, ItemLoan, Item>
     */
    public function currentBorrower(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            ItemLoan::class,
            'item_id', // Foreign key on item_loans table
            'id', // Foreign key on users table
            'id', // Local key on items table
            'user_id' // Local key on item_loans table
        )->whereNull('item_loans.returned_at');
    }
}
