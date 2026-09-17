<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\UserRole;
use App\Support\Organizations\OrganizationContext;

/**
 * Resolves a user's effective roles and permissions from the event-sourced
 * user_roles read model plus static Role enum bindings.
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

    /**
     * Roles held *in the organization currently being acted in*.
     *
     * This is one of the two seams that make the whole capability layer
     * organization-aware: every `isAdmin()` and `can(...)` in the codebase
     * resolves through here, so none of them need to know about organizations
     * themselves. A manager for one operator is an ordinary user everywhere
     * else, and that falls out of this query rather than out of 42 edits.
     *
     * platform_admin is excluded here on purpose. It belongs to no organization
     * and is answered by Gate::before, so it can never be mistaken for an
     * organization role by a query that forgot to filter.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        if ($this->cachedRoleNames !== null) {
            return $this->cachedRoleNames;
        }

        $organizationId = app(OrganizationContext::class)->currentId();

        // No organization in context means no organization roles. Acting
        // without saying where grants nothing, rather than everything.
        if ($organizationId === null) {
            return $this->cachedRoleNames = [];
        }

        return $this->cachedRoleNames = array_values(array_map(
            static fn (mixed $role): string => (string) $role,
            UserRole::query()
                ->where('user_id', $this->getKey())
                ->where('organization_id', $organizationId)
                ->pluck('role')
                ->all()
        ));
    }

    /**
     * Administers the installation rather than any one organization: the only
     * role whose organization is null, and the only one not resolved above.
     */
    public function isPlatformAdmin(): bool
    {
        return UserRole::query()
            ->where('user_id', $this->getKey())
            ->whereNull('organization_id')
            ->where('role', Role::PlatformAdmin->value)
            ->exists();
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
