<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use InvalidArgumentException;

/**
 * Loads and validates the static authorization catalog (config/authorization.php):
 * the set of roles and permissions that exist, plus the static role -> permission
 * map used to resolve effective permissions. See ADR-0021.
 *
 * The catalog is developer-owned and read-only at runtime; this class never writes.
 */
class AuthorizationCatalog
{
    /** @var list<string> */
    private array $permissions;

    /** @var list<string> */
    private array $roles;

    /** @var array<string, list<string>> */
    private array $roleBindings;

    /**
     * @param  array{permissions?: mixed, roles?: mixed, role_bindings?: mixed}  $config
     */
    public function __construct(array $config)
    {
        $this->permissions = $this->parseStringList($config['permissions'] ?? [], 'permissions');
        $this->roles = $this->parseStringList($config['roles'] ?? [], 'roles');
        $this->roleBindings = $this->parseRoleBindings($config['role_bindings'] ?? []);

        $this->validate();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * Role -> permission map with `'*'` already expanded to every permission.
     *
     * @return array<string, list<string>>
     */
    public function roleBindings(): array
    {
        return $this->roleBindings;
    }

    /**
     * @return list<string>
     */
    public function rolesWithPermission(string $permission): array
    {
        $roles = [];

        foreach ($this->roleBindings as $role => $permissions) {
            if (in_array($permission, $permissions, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    private function validate(): void
    {
        foreach ($this->roleBindings as $role => $permissions) {
            if (! $this->hasRole($role)) {
                throw new InvalidArgumentException("role_bindings references unknown role '{$role}'.");
            }

            foreach ($permissions as $permission) {
                if (! $this->hasPermission($permission)) {
                    throw new InvalidArgumentException(
                        "role_bindings for role '{$role}' references unknown permission '{$permission}'."
                    );
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parseStringList(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("authorization.{$key} must be a list.");
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseRoleBindings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('authorization.role_bindings must be a map.');
        }

        $bindings = [];

        /** @var mixed $permissions */
        foreach ($value as $role => $permissions) {
            if ($permissions === '*') {
                $bindings[(string) $role] = $this->permissions;

                continue;
            }

            $bindings[(string) $role] = $this->parseStringList($permissions, "role_bindings.{$role}");
        }

        return $bindings;
    }
}
