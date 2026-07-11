<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\UserRole;

/**
 * Resolves a user's effective roles and permissions from the event-sourced
 * user_roles read model plus static Role enum bindings. See ADR-0021.
 *
 * `admin` is the super-role: it implicitly holds every permission in the
 * enum catalog (and is also short-circuited in Gate::before).
 *
 * Role names are memoized on the instance; call flushPermissionCache() after
 * mutating this user's roles within the same request.
 */
trait HasPermissions
{
    /** @var list<string>|null */
    private ?array $cachedRoleNames = null;

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

    /** @return list<Permission> */
    public function permissions(): array
    {
        if ($this->hasRole(Role::Admin->value)) {
            return Permission::cases();
        }

        $permissions = [];

        foreach ($this->roleNames() as $roleName) {
            $role = Role::tryFrom($roleName);

            if ($role === null) {
                continue;
            }

            foreach ($role->permissions() as $permission) {
                $permissions[$permission->value] = $permission;
            }
        }

        return array_values($permissions);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_map(static fn (Permission $permission): string => $permission->value, $this->permissions());
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @param  list<Permission>  $permissions
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
     * @param  list<Permission>  $permissions
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
    }
}
