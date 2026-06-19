<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires capability-based authorization into Laravel's Gate (ADR-0021):
 *
 * - `admin` is the super-role: it passes every check unconditionally, so
 *   "admin is a strict superset" holds even as permissions are edited at runtime.
 * - Any ability that is a catalog permission resolves to `$user->hasPermission()`.
 * - Other abilities fall through (return null) to normal Gates/Policies.
 *
 * This makes `$user->can('compartment.open')`, `@can`, `authorize()` and
 * `can:` middleware all work with no extra glue.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole(Role::Admin->value)) {
                return true;
            }

            $catalog = $this->app->make(AuthorizationCatalog::class);

            if ($catalog->hasPermission($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }
}
