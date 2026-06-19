<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model (built by RoleProjector). Do not write directly — record a
 * RolePermissionGranted/Revoked event via RoleAggregate instead. See ADR-0021.
 */
class RolePermission extends Model
{
    protected $fillable = [
        'role',
        'permission',
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
