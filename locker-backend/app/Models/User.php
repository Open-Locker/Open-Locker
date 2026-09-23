<?php

namespace App\Models;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\HasPermissions;
use App\Notifications\Auth\WebResetPasswordNotification;
use App\Notifications\Auth\WebVerifyEmailNotification;
use App\Support\Organizations\OrganizationContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property \Carbon\CarbonImmutable|null $email_verified_at
 */
class User extends Authenticatable implements FilamentUser, HasName, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPermissions, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Organizations this user may act in, for Filament's tenant switcher.
     *
     * A platform admin belongs to none by design, so membership would answer
     * with an empty switcher and lock them out of the panel entirely. They get
     * every organization instead — and entering one they are not a member of is
     * recorded.
     *
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isPlatformAdmin()) {
            return Organization::query()->orderBy('name')->get();
        }

        // Membership alone is not enough to be offered an organization here.
        // An ordinary end user belongs to one and holds no role in it, so
        // switching into it would land them in a panel with every resource
        // hidden and nothing for the post-login redirect to reach — which
        // presents as the switcher looping back to where they started.
        return $this->organizations()
            ->whereIn('organizations.id', $this->panelOrganizationIds())
            ->orderBy('name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Organization) {
            return false;
        }

        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->organizations()->whereKey($tenant->getKey())->exists()
            && in_array((string) $tenant->getKey(), $this->panelOrganizationIds(), true);
    }

    /**
     * Organizations in which this person holds a role carrying panel access.
     *
     * @return list<string>
     */
    private function panelOrganizationIds(): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            UserRole::query()
                ->whereIn('role', Role::valuesWithPermission(Permission::PanelAccess))
                ->where('user_id', $this->getKey())
                ->whereNotNull('organization_id')
                ->pluck('organization_id')
                ->all(),
        ));

        return $ids;
    }

    /**
     * Organizations this user belongs to.
     *
     * Membership is recorded separately from any role held inside it: an
     * ordinary end user holds no role row but is still a member, and revoking a
     * role does not remove someone from the organization.
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('joined_at')->withTimestamps();
    }

    /**
     * Get event-sourced role assignments for this user.
     *
     * @return HasMany<UserRole, User>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Get all compartment access entries for this user.
     *
     * @return HasMany<CompartmentAccess, User>
     */
    public function compartmentAccesses(): HasMany
    {
        return $this->hasMany(CompartmentAccess::class);
    }

    /**
     * Get active compartment access entries for this user.
     *
     * @return HasMany<CompartmentAccess, User>
     */
    public function activeCompartmentAccesses(): HasMany
    {
        return $this->compartmentAccesses()
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Groups this user belongs to (inverse of Group::members).
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user')
            ->withPivot(['added_at', 'expires_at', 'revoked_at', 'added_by_user_id', 'removed_by_user_id'])
            ->withTimestamps();
    }

    /**
     * Active group memberships: not revoked and not expired.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function activeGroups(): BelongsToMany
    {
        return $this->groups()
            ->wherePivotNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('group_user.expires_at')
                    ->orWhere('group_user.expires_at', '>', now());
            });
    }

    /**
     * @return HasMany<UserTermsAcceptance, User>
     */
    public function termsAcceptances(): HasMany
    {
        return $this->hasMany(UserTermsAcceptance::class);
    }

    /**
     * @return HasOne<UserTermsAcceptance, User>
     */
    public function latestTermsAcceptance(): HasOne
    {
        return $this->hasOne(UserTermsAcceptance::class)->latestOfMany('accepted_at');
    }

    public function currentTermsVersion(): ?int
    {
        $document = TermsDocument::query()->with('activeVersion')->oldest('id')->first();

        return $document?->activeVersion?->version;
    }

    public function latestAcceptedTermsVersion(): ?int
    {
        return $this->termsAcceptances()
            ->with('acceptedVersion')
            ->latest('accepted_at')
            ->first()?->acceptedVersion?->version;
    }

    public function hasAcceptedCurrentTerms(): bool
    {
        $currentVersion = $this->currentTermsVersion();
        if ($currentVersion === null) {
            return true;
        }

        $acceptedVersion = $this->latestAcceptedTermsVersion();

        return $acceptedVersion !== null
            && $currentVersion === $acceptedVersion;
    }

    /**
     * Check if the user currently has access to a compartment.
     */
    public function hasAccessToCompartment(Compartment $compartment): bool
    {
        return $this->activeCompartmentAccesses()
            ->where('compartment_id', $compartment->id)
            ->exists();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function getFilamentName(): string
    {
        return $this->fullName();
    }

    /**
     * Check if the user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin->value);
    }

    /**
     * Make user an admin through the event-sourced role assignment flow.
     */
    public function makeAdmin(?int $actorUserId = null): void
    {
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($this->id))
            ->grantRole($this->id, Role::Admin->value, $actorUserId, now(), app(OrganizationContext::class)->currentId())
            ->persist();

        $this->flushPermissionCache();
    }

    /**
     * Remove admin privileges from user through the event-sourced role flow.
     */
    public function removeAdmin(?int $actorUserId = null): void
    {
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($this->id))
            ->revokeRole($this->id, Role::Admin->value, $actorUserId, now(), app(OrganizationContext::class)->currentId())
            ->persist();

        $this->flushPermissionCache();
    }

    /**
     * Restrict a user query to members of the organization being acted in.
     *
     * A user is a global identity, so the row itself is not owned by an
     * organization and cannot be tenant-scoped the way a locker bank is. Every
     * place that offers people to pick from has to say so explicitly — a picker
     * that does not is how one operator's staff end up in another's group, and
     * group membership confers compartment access.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeInCurrentOrganization(Builder $query): Builder
    {
        $organizationId = app(OrganizationContext::class)->currentId();

        if ($organizationId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'organizations',
            fn (Builder $organizations) => $organizations->whereKey($organizationId),
        );
    }

    /**
     * Restrict a user query to those the actor is allowed to administer.
     *
     * ADR-0022: a manager may list and view admin accounts but may not mutate
     * them, which includes granting them compartment access directly or through
     * a group. Pickers use this so the panel stops offering what the services
     * would refuse.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeManageableBy(Builder $query, ?self $actor): Builder
    {
        if ($actor?->can(Permission::RolesManage->value)) {
            return $query;
        }

        return $query->whereNotIn(
            'id',
            UserRole::query()->where('role', Role::Admin->value)->select('user_id')
        );
    }

    /**
     * How many administrators exist — in one organization, or across the whole
     * installation when none is given.
     *
     * The distinction matters because "the last admin" is now a per-operator
     * fact. Counting installation-wide would let the only admin of one
     * organization step down while another organization's admin keeps the
     * global count above zero, leaving an operator unable to administer their
     * own tenancy. Each caller says which question it is asking.
     */
    public static function adminRoleCount(?string $organizationId = null): int
    {
        return UserRole::query()
            ->where('role', Role::Admin->value)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->count();
    }

    public static function hasOtherAdmin(int $excludedUserId, ?string $organizationId = null): bool
    {
        return UserRole::query()
            ->where('role', Role::Admin->value)
            ->where('user_id', '!=', $excludedUserId)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();
    }

    /**
     * Send a password reset notification with a public web link.
     *
     * @param  mixed  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new WebResetPasswordNotification((string) $token, $this->email));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new WebVerifyEmailNotification);
    }

    public function sendAdminPasswordResetLink(): string
    {
        return Password::sendResetLink([
            'email' => $this->email,
        ]);
    }

    public function sendAdminVerificationEmail(): bool
    {
        if ($this->hasVerifiedEmail()) {
            return false;
        }

        $this->sendEmailVerificationNotification();

        return true;
    }

    /**
     * Whether this person may reach the panel at all — not which organization
     * they then act in, which is the tenant's job.
     *
     * Filament asks this during authentication, before the tenant middleware
     * has run, so there is no organization in context yet and an
     * organization-scoped check would refuse everyone. The question is answered
     * across every organization they belong to instead: holding panel access
     * anywhere is what gets you through the door, and the tenant decides what
     * you can do once inside.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return UserRole::query()
            ->where('user_id', $this->getKey())
            ->whereIn('role', Role::valuesWithPermission(Permission::PanelAccess))
            ->whereNotNull('organization_id')
            ->exists();
    }

    protected static function booted()
    {
        static::deleting(function (User $user) {
            // Scoped to the organization being administered: deleting an
            // operator's last admin must be refused even when other operators
            // still have theirs.
            if ($user->isAdmin() && ! self::hasOtherAdmin($user->id, app(OrganizationContext::class)->currentId())) {
                return false;
            }
        });
    }
}
