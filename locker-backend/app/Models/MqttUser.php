<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MqttUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'locker_bank_id',
        'username',
        'password_hash',
        'enabled',
        'notes',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function lockerBank(): BelongsTo
    {
        return $this->belongsTo(LockerBank::class);
    }
}
