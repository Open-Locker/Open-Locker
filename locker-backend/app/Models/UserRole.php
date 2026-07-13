<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model (built by UserRoleProjector). Do not write directly — record a
 * UserRoleGranted/Revoked event via UserRoleAggregate instead. See ADR-0021.
 */
class UserRole extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'granted_by_user_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
        ];
    }
}
