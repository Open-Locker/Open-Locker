<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Locker extends Model
{
    /** @use HasFactory<\Database\Factories\LockerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'modbus_address',
        'coil_register',
        'status_register',
    ];

    public function item(): HasOne
    {
        return $this->hasOne(Item::class, 'locker_id', 'id');
    }
}
