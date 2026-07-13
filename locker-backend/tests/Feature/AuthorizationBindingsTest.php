<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\StorableEvents\UserRoleGranted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_manager_gets_only_static_catalog_permissions(): void
    {
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

    public function test_make_admin_records_admin_role_without_legacy_column(): void
    {
        User::factory()->create(); // bootstrap admin

        $user = User::factory()->create();
        $this->assertFalse($user->isAdmin());
        $this->assertFalse(Schema::hasColumn('users', 'is_admin_since'));

        $user->makeAdmin();

        $this->assertTrue($user->fresh()->hasRole(Role::Admin->value));
    }

    public function test_legacy_admin_column_migration_backfills_admin_roles_before_drop(): void
    {
        User::factory()->create(); // bootstrap admin
        $legacyAdmin = User::factory()->create();
        $grantedAt = now()->subDay();

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('is_admin_since')->nullable();
        });

        DB::table('users')
            ->where('id', $legacyAdmin->id)
            ->update(['is_admin_since' => $grantedAt]);

        $this->assertFalse($legacyAdmin->fresh()->hasRole(Role::Admin->value));
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $legacyAdmin->id,
            'role' => Role::Admin->value,
        ]);

        $migration = require database_path('migrations/2026_07_11_000001_backfill_admin_roles_and_drop_is_admin_since.php');
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'is_admin_since'));
        $this->assertTrue($legacyAdmin->fresh()->hasRole(Role::Admin->value));
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $legacyAdmin->id,
            'role' => Role::Admin->value,
        ]);
        $this->assertDatabaseHas('stored_events', [
            'aggregate_uuid' => UserRoleAggregate::aggregateUuidFor($legacyAdmin->id),
            'event_class' => UserRoleGranted::class,
        ]);
    }

    public function test_last_admin_delete_guard_uses_user_roles(): void
    {
        $admin = User::factory()->create();

        $this->assertFalse($admin->delete());
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
        ]);
    }
}
