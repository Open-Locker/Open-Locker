<?php

namespace App\Models;

use Database\Factories\CompartmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Compartment extends Model
{
    /** @use HasFactory<CompartmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'locker_bank_id',
        'number',
    ];

    public static function booted(): void
    {
        static::creating(function (self $compartment) {
            if (is_null($compartment->number)) {
                $maxNumber = self::where('locker_bank_id', $compartment->locker_bank_id)->max('number');
                $compartment->number = $maxNumber + 1;
            }
        });
    }

    public function item(): HasOne
    {
        return $this->hasOne(Item::class);
    }

    public function lockerBank(): BelongsTo
    {
        return $this->belongsTo(LockerBank::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // No longer needed as 'status' is removed from this model.
            // Status will be handled via events.
        ];
    }
}
