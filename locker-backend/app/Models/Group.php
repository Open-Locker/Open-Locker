<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'name',
        'description',
        'created_by_user_id',
        'archived_at',
        'archived_by_user_id',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, Group>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, Group>
     */
    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_user')
            ->withPivot(['added_at', 'expires_at', 'revoked_at', 'added_by_user_id', 'removed_by_user_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<GroupCompartmentAccess, Group>
     */
    public function compartmentAccesses(): HasMany
    {
        return $this->hasMany(GroupCompartmentAccess::class);
    }
}
