<?php

namespace App\Models;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\HasPermissions;
use App\Notifications\Auth\WebResetPasswordNotification;
use App\Notifications\Auth\WebVerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property \Carbon\CarbonImmutable|null $email_verified_at
 */
class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
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
            ->grantRole($this->id, Role::Admin->value, $actorUserId, now())
            ->persist();

        $this->flushPermissionCache();
    }

    /**
     * Remove admin privileges from user through the event-sourced role flow.
     */
    public function removeAdmin(?int $actorUserId = null): void
    {
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($this->id))
            ->revokeRole($this->id, Role::Admin->value, $actorUserId, now())
            ->persist();

        $this->flushPermissionCache();
    }

    public static function adminRoleCount(): int
    {
        return UserRole::query()
            ->where('role', Role::Admin->value)
            ->count();
    }

    public static function hasOtherAdmin(int $excludedUserId): bool
    {
        return UserRole::query()
            ->where('role', Role::Admin->value)
            ->where('user_id', '!=', $excludedUserId)
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

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can(Permission::PanelAccess->value);
    }

    protected static function booted()
    {
        static::created(function (User $user) {
            // First registered user becomes admin through an auditable system event.
            if (User::count() === 1) {
                $user->makeAdmin();
            }
        });

        static::deleting(function (User $user) {
            if ($user->isAdmin() && ! self::hasOtherAdmin($user->id)) {
                return false;
            }
        });
    }
}
