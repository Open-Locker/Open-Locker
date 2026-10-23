<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operator. Everything a user can reach belongs to exactly one, and nothing
 * in the product shows two at once.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'slug'];

    /**
     * The people who belong to this operator.
     *
     * Named for the related model rather than the domain word "members"
     * because Filament resolves the inverse of User::organizations() by
     * convention when attaching — a members() alias alone left the attach
     * action calling an Organization::users() that did not exist.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('joined_at')->withTimestamps();
    }

    /** @return HasMany<LockerBank, $this> */
    public function lockerBanks(): HasMany
    {
        return $this->hasMany(LockerBank::class);
    }
}
