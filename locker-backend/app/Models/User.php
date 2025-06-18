<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
     * Get all loans for this user
     *
     * @return HasMany<ItemLoan, User>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(ItemLoan::class);
    }

    /**
     * Get active loans for this user
     *
     * @return HasMany<ItemLoan, User>
     */
    public function activeLoans(): HasMany
    {
        return $this->hasMany(ItemLoan::class)->where('status', 'active');
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

    public function canAccessPanel(Panel $panel): bool
    {
        if (request()->routeIs([
            'filament.admin.auth.email-verification.*',
            'filament.admin.pages.auth.email-verification.*',
        ])) {
            return true;
        }

        if ($this->is_admin_since && !$this->hasVerifiedEmail()) {
            $currentRoute = request()->route()?->getName();

            if (!str_contains($currentRoute ?? '', 'email-verification')) {
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
