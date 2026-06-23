<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\UserRole;
use App\Support\Authorization\AuthorizationCatalog;

/**
 * Resolves a user's effective roles and permissions from the event-sourced
 * read models (user_roles, role_permissions). See ADR-0021.
 *
 * `admin` is the super-role: it implicitly holds every permission in the
 * catalog (and is also short-circuited in Gate::before).
 *
 * Results are memoized on the instance; call flushPermissionCache() after
 * mutating this user's roles within the same request.
 */
trait HasPermissions
{
    /** @var list<string>|null */
    private ?array $cachedRoleNames = null;

    /** @var list<string>|null */
    private ?array $cachedPermissionNames = null;

    /** @return list<string> */
    public function roleNames(): array
    {
        return $this->cachedRoleNames ??= UserRole::query()
            ->where('user_id', $this->getKey())
            ->pluck('role')
            ->all();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->cachedPermissionNames !== null) {
            return $this->cachedPermissionNames;
        }

        $catalog = app(AuthorizationCatalog::class);

        if ($this->hasRole(Role::Admin->value)) {
            return $this->cachedPermissionNames = $catalog->permissions();
        }

        $roles = $this->roleNames();

        if ($roles === []) {
            return $this->cachedPermissionNames = [];
        }

        return $this->cachedPermissionNames = RolePermission::query()
            ->whereIn('role', $roles)
            ->whereNull('revoked_at')
            ->pluck('permission')
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    public function flushPermissionCache(): void
    {
        $this->cachedRoleNames = null;
        $this->cachedPermissionNames = null;
    }
}
