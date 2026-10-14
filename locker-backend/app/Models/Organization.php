<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    public const DEFAULT_SLUG = 'default';

    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Members, from the read model built by OrganizationMembershipProjector.
     * Do not attach or detach directly; use OrganizationMembershipService.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['added_by_user_id', 'joined_at']);
    }
}
