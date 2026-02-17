<?php

namespace App\Models;

use Database\Factories\CompartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Compartment extends Model
{
    /** @use HasFactory<CompartmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'locker_bank_id',
        'number',
        'slave_id',
        'address',
        'last_opened_at',
        'last_open_failed_at',
        'last_open_transaction_id',
        'last_open_error_code',
        'last_open_error_message',
    ];

    public static function booted(): void
    {
        static::creating(function (self $compartment) {
            if (is_null($compartment->number)) {
                $maxNumber = self::where('locker_bank_id', $compartment->locker_bank_id)->max('number');
                $compartment->number = $maxNumber + 1;
            }
        });

        static::saving(function (self $compartment) {
            if (is_null($compartment->locker_bank_id) || is_null($compartment->slave_id) || is_null($compartment->address)) {
                return;
            }

            $query = self::query()
                ->where('locker_bank_id', $compartment->locker_bank_id)
                ->where('slave_id', $compartment->slave_id)
                ->where('address', $compartment->address);

            if ($compartment->exists) {
                $query->where('id', '!=', $compartment->id);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'address' => 'This Slave ID + Address combination is already used in this locker bank.',
                ]);
            }
        });
    }

    public function item(): HasOne
    {
        return $this->hasOne(Item::class);
    }

    /**
     * @return HasMany<CompartmentOpenRequest, Compartment>
     */
    public function openRequests(): HasMany
    {
        return $this->hasMany(CompartmentOpenRequest::class, 'compartment_id', 'id');
    }

    /**
     * @return HasOne<CompartmentOpenRequest, Compartment>
     */
    public function latestOpenRequest(): HasOne
    {
        return $this->hasOne(CompartmentOpenRequest::class, 'compartment_id', 'id')->latestOfMany('requested_at');
    }

    public function lockerBank(): BelongsTo
    {
        return $this->belongsTo(LockerBank::class);
    }

    /**
     * @return HasMany<CompartmentAccess, Compartment>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(CompartmentAccess::class);
    }

    /**
     * @return HasMany<CompartmentAccess, Compartment>
     */
    public function activeAccesses(): HasMany
    {
        return $this->accesses()
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'slave_id' => 'integer',
            'address' => 'integer',
            'last_opened_at' => 'datetime',
            'last_open_failed_at' => 'datetime',
        ];
    }
}
