<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCompartmentAccess extends Model
{
    /** @use HasFactory<\Database\Factories\GroupCompartmentAccessFactory> */
    use HasFactory;

    protected $fillable = [
        'group_id',
        'compartment_id',
        'granted_by_user_id',
        'revoked_by_user_id',
        'granted_at',
        'expires_at',
        'revoked_at',
        'notes',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Group, GroupCompartmentAccess>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Compartment, GroupCompartmentAccess>
     */
    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class);
    }

    /**
     * @param  Builder<GroupCompartmentAccess>  $query
     * @return Builder<GroupCompartmentAccess>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
