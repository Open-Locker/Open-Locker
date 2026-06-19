<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\RoleAggregate;
use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\StorableEvents\UserRoleGranted;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class AuthorizationBindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_user_becomes_admin_via_an_event(): void
    {
        $first = User::factory()->create();

        $this->assertTrue($first->hasRole(Role::Admin->value));
        $this->assertTrue($first->isAdmin());

        // The grant is recorded as an auditable event (system actor = null).
        $event = EloquentStoredEvent::query()
            ->where('event_class', UserRoleGranted::class)
            ->where('aggregate_uuid', UserRoleAggregate::aggregateUuidFor($first->id))
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('admin', $event->event_properties['role']);
        $this->assertNull($event->event_properties['actorUserId']);
    }

    public function test_admin_is_a_super_role_passing_every_permission(): void
    {
        $admin = User::factory()->create(); // bootstrap admin

        $this->assertTrue($admin->can(Permission::LockerBankConfigure->value));
        $this->assertTrue($admin->can(Permission::SystemConfigure->value));
        $this->assertTrue($admin->can(Permission::RolesManage->value));
    }

    public function test_manager_gets_only_seeded_permissions(): void
    {
        $this->seed(AuthorizationSeeder::class);

        User::factory()->create(); // bootstrap admin (so the next user is not first)
        $manager = User::factory()->create();

        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();

        $this->assertTrue($manager->can(Permission::CompartmentOpen->value));
        $this->assertTrue($manager->can(Permission::CompartmentAccessManage->value));
        $this->assertFalse($manager->can(Permission::LockerBankConfigure->value));
        $this->assertFalse($manager->can(Permission::RolesManage->value));
        $this->assertFalse($manager->isAdmin());
    }

    public function test_revoking_a_role_removes_its_permissions(): void
    {
        $this->seed(AuthorizationSeeder::class);
        User::factory()->create(); // bootstrap admin

        $user = User::factory()->create();
        $uuid = UserRoleAggregate::aggregateUuidFor($user->id);

        UserRoleAggregate::retrieve($uuid)->grantRole($user->id, Role::Manager->value, null, now())->persist();
        $user->flushPermissionCache();
        $this->assertTrue($user->can(Permission::CompartmentOpen->value));

        UserRoleAggregate::retrieve($uuid)->revokeRole($user->id, Role::Manager->value, null, now())->persist();
        $user->flushPermissionCache();
        $this->assertFalse($user->can(Permission::CompartmentOpen->value));
    }

    public function test_role_permission_binding_is_dynamic(): void
    {
        $this->seed(AuthorizationSeeder::class);
        User::factory()->create(); // bootstrap admin

        $user = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($user->id))
            ->grantRole($user->id, Role::Manager->value, null, now())
            ->persist();

        $this->assertFalse($user->fresh()->can(Permission::LockerBankConfigure->value));

        // Admin grants manager a new permission at runtime.
        RoleAggregate::retrieve(RoleAggregate::aggregateUuidFor(Role::Manager->value))
            ->grantPermission(Role::Manager->value, Permission::LockerBankConfigure->value, null, now())
            ->persist();

        $this->assertTrue($user->fresh()->can(Permission::LockerBankConfigure->value));
    }

    public function test_make_admin_dual_writes_legacy_column_and_role(): void
    {
        User::factory()->create(); // bootstrap admin

        $user = User::factory()->create();
        $this->assertFalse($user->isAdmin());

        $user->makeAdmin();

        $this->assertNotNull($user->fresh()->is_admin_since);
        $this->assertTrue($user->fresh()->hasRole(Role::Admin->value));
    }
}
