<?php

namespace App\Models;

use App\Enums\LockerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LockerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read  CarbonImmutable $created_at
 * @property-read  CarbonImmutable $updated_at
 * @property-read  Item $item
 * @property-read  string $name
 * @property-read  int $unit_id
 * @property-read  int $coil_address
 * @property-read  int $input_address
 *
 * @uses LockerFactory
 */
class Locker extends Model
{
    /** @use HasFactory<LockerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'unit_id',
        'coil_address',
        'input_address',
        'status',
    ];

    public function item(): HasOne
    {
        return $this->hasOne(Item::class, 'locker_id', 'id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LockerStatus::class,
        ];
    }
}
