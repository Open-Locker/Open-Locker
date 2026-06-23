<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model (built by RoleProjector). Do not write directly — record a
 * RolePermissionGranted/Revoked event via RoleAggregate instead. See ADR-0021.
 *
 * @property string $role
 * @property string $permission
 * @property int|null $granted_by_user_id
 * @property \Illuminate\Support\Carbon|null $granted_at
 * @property int|null $revoked_by_user_id
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class RolePermission extends Model
{
    protected $fillable = [
        'role',
        'permission',
        'granted_by_user_id',
        'granted_at',
        'revoked_by_user_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
