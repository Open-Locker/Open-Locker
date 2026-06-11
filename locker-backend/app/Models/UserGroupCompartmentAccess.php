<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived effective-access read model. Rows are maintained exclusively by
 * GroupProjector::recomputeGroup(); never write this table directly.
 */
class UserGroupCompartmentAccess extends Model
{
    protected $fillable = [
        'user_id',
        'compartment_id',
        'group_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, UserGroupCompartmentAccess>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Compartment, UserGroupCompartmentAccess>
     */
    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class);
    }

    /**
     * @return BelongsTo<Group, UserGroupCompartmentAccess>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * No revoked_at column: a row only exists while a source grants it, so
     * "active" is purely the expiry check.
     *
     * @param  Builder<UserGroupCompartmentAccess>  $query
     * @return Builder<UserGroupCompartmentAccess>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
