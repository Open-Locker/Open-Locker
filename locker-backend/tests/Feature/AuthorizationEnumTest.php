<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use Tests\TestCase;

class AuthorizationEnumTest extends TestCase
{
    public function test_permission_and_role_values_are_available(): void
    {
        $this->assertContains('manager', Role::values());
        $this->assertContains('compartment.open', Permission::values());
        $this->assertNotContains('does.not.exist', Permission::values());
    }

    public function test_admin_role_holds_all_permissions(): void
    {
        $this->assertSame(
            Permission::cases(),
            Role::Admin->permissions(),
            'admin should resolve to every permission in the enum catalog.'
        );
    }

    public function test_manager_role_binding_matches_issue_95_capabilities(): void
    {
        $managerPermissions = Role::Manager->permissions();

        // #95: manager may do these...
        $this->assertContains(Permission::PanelAccess, $managerPermissions);
        $this->assertContains(Permission::UsersManage, $managerPermissions);
        $this->assertContains(Permission::CompartmentAccessManage, $managerPermissions);
        $this->assertContains(Permission::CompartmentOpen, $managerPermissions);

        // ...but NOT these.
        $this->assertNotContains(Permission::RolesManage, $managerPermissions);
        $this->assertNotContains(Permission::LockerBankConfigure, $managerPermissions);
        $this->assertNotContains(Permission::SystemConfigure, $managerPermissions);
    }

    public function test_roles_can_be_resolved_by_permission(): void
    {
        $roles = Role::valuesWithPermission(Permission::CompartmentOpen);

        $this->assertContains(Role::Admin->value, $roles);
        $this->assertContains(Role::Manager->value, $roles);
        $this->assertNotContains(Role::User->value, $roles);
    }

    public function test_permissions_have_descriptions(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotSame('', $permission->description());
        }
    }
}
