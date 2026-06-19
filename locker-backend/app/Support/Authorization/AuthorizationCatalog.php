<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates the static authorization catalog (config/authorization.yaml):
 * the set of roles and permissions that exist, plus the default role -> permission
 * map used to seed the dynamic bindings. See ADR-0021.
 *
 * The catalog is developer-owned and read-only at runtime; this class never writes.
 * Bound as a singleton, so the YAML is parsed and validated once per process.
 */
class AuthorizationCatalog
{
    /** @var list<string> */
    private array $permissions;

    /** @var list<string> */
    private array $roles;

    /** @var array<string, list<string>> */
    private array $defaultBindings;

    public function __construct(string $yamlPath)
    {
        /** @var array{permissions?: mixed, roles?: mixed, default_bindings?: mixed} $parsed */
        $parsed = Yaml::parseFile($yamlPath) ?? [];

        $this->permissions = $this->parseStringList($parsed['permissions'] ?? [], 'permissions');
        $this->roles = $this->parseStringList($parsed['roles'] ?? [], 'roles');
        $this->defaultBindings = $this->parseDefaultBindings($parsed['default_bindings'] ?? []);

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
    public function defaultBindings(): array
    {
        return $this->defaultBindings;
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
        foreach ($this->defaultBindings as $role => $permissions) {
            if (! $this->hasRole($role)) {
                throw new InvalidArgumentException("default_bindings references unknown role '{$role}'.");
            }

            foreach ($permissions as $permission) {
                if (! $this->hasPermission($permission)) {
                    throw new InvalidArgumentException(
                        "default_bindings for role '{$role}' references unknown permission '{$permission}'."
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
    private function parseDefaultBindings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('authorization.default_bindings must be a map.');
        }

        $bindings = [];

        /** @var mixed $permissions */
        foreach ($value as $role => $permissions) {
            if ($permissions === '*') {
                $bindings[(string) $role] = $this->permissions;

                continue;
            }

            $bindings[(string) $role] = $this->parseStringList($permissions, "default_bindings.{$role}");
        }

        return $bindings;
    }
}
