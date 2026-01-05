<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $location_description
 * @property-read string $provisioning_token
 * @property-read \Illuminate\Support\CarbonImmutable|null $provisioned_at
 * @property-read \Illuminate\Support\CarbonImmutable|null $last_heartbeat_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compartment> $compartments
 * @property-read int|null $compartments_count
 */
class LockerBank extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'location_description',
        'provisioning_token',
        'provisioned_at',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    public static function booted(): void
    {
        static::creating(function (self $lockerBank) {
            $lockerBank->provisioning_token = Str::random(64);
        });
    }

    public function compartments(): HasMany
    {
        return $this->hasMany(Compartment::class);
    }
}
