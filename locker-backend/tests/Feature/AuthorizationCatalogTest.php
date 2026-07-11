<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\Authorization\AuthorizationCatalog;
use Tests\TestCase;

class AuthorizationCatalogTest extends TestCase
{
    private function catalog(): AuthorizationCatalog
    {
        return app(AuthorizationCatalog::class);
    }

    public function test_catalog_loads_roles_and_permissions(): void
    {
        $catalog = $this->catalog();

        $this->assertContains('manager', $catalog->roles());
        $this->assertContains('compartment.open', $catalog->permissions());
        $this->assertTrue($catalog->hasRole('admin'));
        $this->assertTrue($catalog->hasPermission('roles.manage'));
        $this->assertFalse($catalog->hasPermission('does.not.exist'));
    }

    public function test_admin_role_binding_expands_star_to_all_permissions(): void
    {
        $catalog = $this->catalog();

        $this->assertSame(
            $catalog->permissions(),
            $catalog->roleBindings()['admin'],
            'admin (*) should resolve to every permission in the catalog.'
        );
    }

    public function test_manager_role_binding_matches_issue_95_capabilities(): void
    {
        $managerPermissions = $this->catalog()->roleBindings()['manager'];

        // #95: manager may do these...
        $this->assertContains('panel.access', $managerPermissions);
        $this->assertContains('users.manage', $managerPermissions);
        $this->assertContains('compartment.access.manage', $managerPermissions);
        $this->assertContains('compartment.open', $managerPermissions);

        // ...but NOT these.
        $this->assertNotContains('roles.manage', $managerPermissions);
        $this->assertNotContains('lockerbank.configure', $managerPermissions);
        $this->assertNotContains('system.configure', $managerPermissions);
    }

    public function test_catalog_can_resolve_roles_with_a_permission(): void
    {
        $roles = $this->catalog()->rolesWithPermission(Permission::CompartmentOpen->value);

        $this->assertContains(Role::Admin->value, $roles);
        $this->assertContains(Role::Manager->value, $roles);
        $this->assertNotContains(Role::User->value, $roles);
    }

    /**
     * Parity guard: the Permission enum (code references) and the config catalog
     * must match exactly. This is what makes "deleting a still-used permission
     * from the config" fail CI.
     */
    public function test_permission_enum_and_config_are_in_sync(): void
    {
        $enumValues = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        $configValues = $this->catalog()->permissions();

        sort($enumValues);
        sort($configValues);

        $this->assertSame(
            $configValues,
            $enumValues,
            'config/authorization.php permissions and the Permission enum must match exactly.'
        );
    }

    public function test_role_enum_and_config_are_in_sync(): void
    {
        $enumValues = array_map(static fn (Role $r): string => $r->value, Role::cases());
        $configValues = $this->catalog()->roles();

        sort($enumValues);
        sort($configValues);

        $this->assertSame(
            $configValues,
            $enumValues,
            'config/authorization.php roles and the Role enum must match exactly.'
        );
    }
}
