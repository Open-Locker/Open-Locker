<?php

namespace App\Models;

use App\Notifications\Auth\HybridResetPasswordNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
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
            'is_admin_since' => 'datetime',
        ];
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
     * Check if the user currently has access to a compartment.
     */
    public function hasAccessToCompartment(Compartment $compartment): bool
    {
        return $this->activeCompartmentAccesses()
            ->where('compartment_id', $compartment->id)
            ->exists();
    }

    /**
     * Check if the user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->is_admin_since !== null;
    }

    /**
     * Make user an admin
     */
    public function makeAdmin(): void
    {
        $this->is_admin_since = now();
        $this->save();
    }

    /**
     * Remove admin privileges from user
     */
    public function removeAdmin(): void
    {
        $this->is_admin_since = null;
        $this->save();
    }

    /**
     * Send a password reset notification with app and web links.
     *
     * @param  mixed  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new HybridResetPasswordNotification((string) $token, $this->email));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (request()->routeIs([
            'filament.admin.auth.email-verification.*',
            'filament.admin.pages.auth.email-verification.*',
        ])) {
            return true;
        }

        if ($this->is_admin_since && ! $this->hasVerifiedEmail()) {
            $currentRoute = request()->route()?->getName();

            if (! str_contains($currentRoute ?? '', 'email-verification')) {
                redirect()->to($panel->route('auth.email-verification.prompt'))->send();
            }

            return true;
        }

        return $this->isAdmin() && $this->hasVerifiedEmail();
    }

    protected static function booted()
    {
        static::created(function (User $user) {
            if (User::count() === 1) {
                $user->is_admin_since = now();
                $user->save();
            }
        });

        static::deleting(function (User $user) {
            if ($user->isAdmin() && User::whereNotNull('is_admin_since')->count() <= 1) {
                return false;
            }
        });
    }
}
