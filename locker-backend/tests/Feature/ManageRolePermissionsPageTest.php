<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\ManageRolePermissions;
use App\Filament\Pages\ManageRolePermissionsForRole;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageRolePermissionsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function activePermissions(string $role): array
    {
        return RolePermission::query()
            ->where('role', $role)
            ->whereNull('revoked_at')
            ->pluck('permission')
            ->sort()
            ->values()
            ->all();
    }

    public function test_overview_lists_all_roles(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ManageRolePermissions::class)
            ->assertOk()
            ->assertSee(__('User role'))
            ->assertSee(__('Manager'))
            ->assertSee(__('Admin'));
    }

    public function test_grant_action_grants_an_inactive_permission(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();

        $this->assertNotContains(Permission::GroupsManage->value, $this->activePermissions(Role::Manager->value));

        $this->actingAs($admin);

        Livewire::test(ManageRolePermissionsForRole::class, ['role' => Role::Manager->value])
            ->callAction(TestAction::make('grant')->table(Permission::GroupsManage->value))
            ->assertHasNoErrors();

        $this->assertContains(Permission::GroupsManage->value, $this->activePermissions(Role::Manager->value));
    }

    public function test_revoke_action_soft_revokes_and_keeps_audit_row(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();

        $this->assertContains(Permission::CompartmentOpen->value, $this->activePermissions(Role::Manager->value));

        $this->actingAs($admin);

        Livewire::test(ManageRolePermissionsForRole::class, ['role' => Role::Manager->value])
            ->callAction(TestAction::make('revoke')->table(Permission::CompartmentOpen->value))
            ->assertHasNoErrors();

        $this->assertNotContains(Permission::CompartmentOpen->value, $this->activePermissions(Role::Manager->value));

        // The row is kept for the audit trail, with the revoke recorded.
        $binding = RolePermission::query()
            ->where('role', Role::Manager->value)
            ->where('permission', Permission::CompartmentOpen->value)
            ->firstOrFail();

        $this->assertNotNull($binding->revoked_at);
        $this->assertSame($admin->id, $binding->revoked_by_user_id);
    }

    public function test_admin_role_actions_are_hidden(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ManageRolePermissionsForRole::class, ['role' => Role::Admin->value])
            ->assertActionHidden(TestAction::make('grant')->table(Permission::SystemConfigure->value))
            ->assertActionHidden(TestAction::make('revoke')->table(Permission::SystemConfigure->value));

        $this->assertTrue($admin->fresh()->can(Permission::SystemConfigure->value));
    }

    public function test_unknown_role_returns_404(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/rollen-berechtigungen/not-a-role')
            ->assertNotFound();
    }

    public function test_non_manager_cannot_access_pages(): void
    {
        $this->seed(AuthorizationSeeder::class);
        User::factory()->create(); // bootstrap admin

        $plain = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($plain->id))
            ->grantRole($plain->id, Role::Manager->value, null, now())
            ->persist();

        // Manager does NOT have roles.manage by default.
        $this->assertFalse($plain->fresh()->can(Permission::RolesManage->value));

        $this->actingAs($plain->fresh());
        $this->assertFalse(ManageRolePermissions::canAccess());
        $this->assertFalse(ManageRolePermissionsForRole::canAccess());
    }
}
