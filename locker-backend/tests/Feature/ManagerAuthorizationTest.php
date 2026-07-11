<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\GroupAccessService;
use App\Services\UserAdministrationService;
use App\StorableEvents\CompartmentOpenAuthorized;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class ManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        User::factory()->create(); // bootstrap admin (so manager is not the first user)

        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();

        return $manager;
    }

    private function makeRegularUser(): User
    {
        User::factory()->create(); // bootstrap admin
        $user = User::factory()->create();
        $user->removeAdmin();

        return $user;
    }

    public function test_manager_can_access_panel(): void
    {
        $manager = $this->makeManager();

        // The panel has no dashboard; its root redirects to the first nav item.
        // A redirect (rather than 403) confirms the manager can enter the panel.
        $response = $this->actingAs($manager)->get(route('filament.admin.home'));

        $response->assertRedirect();
    }

    public function test_manager_can_grant_compartment_access_to_a_user(): void
    {
        $manager = $this->makeManager();
        $target = User::factory()->create();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($target, $compartment, actor: $manager);

        $this->assertDatabaseHas('compartment_accesses', [
            'user_id' => $target->id,
            'compartment_id' => (string) $compartment->id,
            'granted_by_user_id' => $manager->id,
            'revoked_at' => null,
        ]);
    }

    public function test_manager_cannot_grant_compartment_access_to_admin_user(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(CompartmentAccessService::class)->grantAccess($admin, $compartment, actor: $manager);
    }

    public function test_manager_cannot_revoke_compartment_access_from_admin_user(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($admin, $compartment, actor: User::query()->firstOrFail());

        $this->expectException(AuthorizationException::class);

        app(CompartmentAccessService::class)->revokeAccess($admin, $compartment, actor: $manager);
    }

    public function test_manager_cannot_grant_admin_user_access_from_compartment_screen(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        \Livewire\Livewire::actingAs($manager)
            ->test(UserAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->callTableAction('grantAccess', data: [
                'user_id' => $admin->id,
                'expires_at' => null,
                'notes' => null,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('compartment_accesses', [
            'user_id' => $admin->id,
            'compartment_id' => (string) $compartment->id,
            'revoked_at' => null,
        ]);
    }

    public function test_manager_can_open_any_compartment_via_manager_override(): void
    {
        $manager = $this->makeManager();
        $compartment = Compartment::factory()->create(); // manager has NO explicit access

        // An authorized open drives the reactor -> LockerService -> MQTT publish;
        // mock the hardware boundary so the test doesn't hit a real broker.
        $this->mock(\App\Services\LockerService::class, function ($mock): void {
            $mock->shouldReceive('openCompartment');
        });

        $decision = app(CompartmentAccessService::class)->requestOpen($manager, $compartment);

        $this->assertTrue($decision['authorized']);

        $authorized = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenAuthorized::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($authorized);
        $this->assertSame('manager_override', $authorized->event_properties['authorizationType']);
    }

    public function test_regular_user_cannot_grant_compartment_access(): void
    {
        $user = $this->makeRegularUser();
        $target = User::factory()->create();
        $compartment = Compartment::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(CompartmentAccessService::class)->grantAccess($target, $compartment, actor: $user);
    }

    public function test_manager_can_manage_groups(): void
    {
        $manager = $this->makeManager();

        $group = app(GroupAccessService::class)->createGroup('Crew', actor: $manager);

        $this->assertSame('Crew', $group->name);
    }

    public function test_manager_cannot_configure_locker_banks_or_manage_roles(): void
    {
        $manager = $this->makeManager();

        $this->assertFalse($manager->can(Permission::LockerBankConfigure->value));
        $this->assertFalse($manager->can(Permission::RolesManage->value));
        $this->assertTrue($manager->can(Permission::SystemConfigure->value));
        $this->assertFalse($manager->isAdmin());
    }

    public function test_regular_user_cannot_access_panel(): void
    {
        $user = $this->makeRegularUser();

        $response = $this->actingAs($user)->get(route('filament.admin.home'));

        $response->assertForbidden();
    }

    public function test_manager_sees_only_permitted_filament_resources(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager);

        $this->assertTrue(\App\Filament\Resources\UserResource::canAccess());
        $this->assertFalse(\App\Filament\Resources\LockerBankResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\GroupResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\TermsDocumentVersionResource::canAccess());
    }

    public function test_admin_sees_all_filament_resources(): void
    {
        $admin = User::factory()->create(); // bootstrap admin
        $this->actingAs($admin);

        $this->assertTrue(\App\Filament\Resources\UserResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\LockerBankResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\GroupResource::canAccess());
        $this->assertTrue(\App\Filament\Resources\TermsDocumentVersionResource::canAccess());
    }

    public function test_admin_can_assign_manager_role_via_panel_action(): void
    {
        $admin = User::factory()->create(); // bootstrap admin
        $target = User::factory()->create();

        \Livewire\Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('manageRoles', data: ['roles' => [Role::Manager->value]])
            ->assertHasNoActionErrors();

        $this->assertTrue($target->fresh()->hasRole(Role::Manager->value));
        $this->assertTrue($target->fresh()->can(Permission::CompartmentOpen->value));
    }

    public function test_manager_can_manage_regular_user_records(): void
    {
        $manager = $this->makeManager();
        $target = User::factory()->create();

        $this->actingAs($manager);
        $this->assertTrue(UserResource::canView($target));
        $this->assertTrue(UserResource::canEdit($target));
        $this->assertTrue(UserResource::canDelete($target));

        \Livewire\Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'first_name' => 'Updated',
                'last_name' => $target->last_name,
                'email' => $target->email,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated', $target->fresh()->first_name);
    }

    public function test_manager_can_view_but_not_edit_admin_user_records(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $originalFirstName = $admin->first_name;

        $this->actingAs($manager);
        $this->assertTrue(UserResource::canView($admin));
        $this->assertFalse(UserResource::canEdit($admin));
        $this->assertFalse(UserResource::canDelete($admin));

        $response = $this->actingAs($manager)->get(UserResource::getUrl('edit', ['record' => $admin]));

        $response->assertOk();

        \Livewire\Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm([
                'first_name' => 'Updated',
                'last_name' => $admin->last_name,
                'email' => $admin->email,
            ])
            ->call('save')
            ->assertForbidden();

        $this->assertSame($originalFirstName, $admin->fresh()->first_name);
    }

    public function test_manager_user_table_includes_admin_records_as_read_only(): void
    {
        $manager = $this->makeManager();
        $regular = User::factory()->create();
        $admin = User::factory()->create();
        $admin->makeAdmin();

        \Livewire\Livewire::actingAs($manager)
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords([$regular, $admin])
            ->assertTableActionVisible('edit', $regular)
            ->assertTableActionVisible('edit', $admin);
    }

    public function test_manager_bulk_delete_cannot_delete_admin_user_records(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();

        \Livewire\Livewire::actingAs($manager)
            ->test(ListUsers::class)
            ->callTableBulkAction('delete', [$admin]);

        $this->assertNotNull($admin->fresh());
    }

    public function test_manager_sees_grant_and_revoke_access_actions_on_a_user(): void
    {
        $manager = $this->makeManager();
        $target = User::factory()->create();

        \Livewire\Livewire::actingAs($manager)
            ->test(\App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $target,
                'pageClass' => EditUser::class,
            ])
            ->assertSuccessful()
            ->assertSee(__('Grant access'));
    }

    public function test_regular_user_does_not_see_grant_access_action(): void
    {
        $owner = $this->makeRegularUser();
        $target = User::factory()->create();

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $target,
                'pageClass' => EditUser::class,
            ])
            ->assertDontSee('Grant access');
    }

    public function test_manager_cannot_manage_compartment_access_for_admin_users(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->create();
        $admin->makeAdmin();

        \Livewire\Livewire::actingAs($manager)
            ->test(\App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $admin,
                'pageClass' => EditUser::class,
            ])
            ->assertDontSee(__('Grant access'));
    }

    public function test_manager_cannot_see_role_management_action(): void
    {
        User::factory()->create(); // bootstrap admin
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();

        \Livewire\Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $manager->getRouteKey()])
            ->assertActionHidden('manageRoles')
            ->assertActionHidden('setAsAdmin')
            ->assertActionHidden('removeAdmin');
    }

    public function test_manager_cannot_make_user_admin_through_service(): void
    {
        $manager = $this->makeManager();
        $target = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(UserAdministrationService::class)->makeAdmin($manager, $target);
    }

    public function test_manager_cannot_trigger_sensitive_actions_for_admin_users(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->unverified()->create();
        $admin->makeAdmin();

        \Livewire\Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertActionHidden('sendPasswordResetLink')
            ->assertActionHidden('sendVerificationEmail')
            ->assertActionHidden('manageRoles')
            ->assertActionHidden('setAsAdmin')
            ->assertActionHidden('removeAdmin')
            ->assertActionHidden('delete');
    }

    public function test_manager_cannot_trigger_sensitive_admin_user_action_through_service(): void
    {
        $manager = $this->makeManager();
        $admin = User::factory()->unverified()->create();
        $admin->makeAdmin();

        $this->expectException(AuthorizationException::class);

        app(UserAdministrationService::class)->sendPasswordResetLink($manager, $admin);
    }

    public function test_admin_can_manage_admin_user_records(): void
    {
        $admin = User::factory()->create(); // bootstrap admin
        $targetAdmin = User::factory()->create();
        $targetAdmin->makeAdmin();

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canView($targetAdmin));
        $this->assertTrue(UserResource::canEdit($targetAdmin));
        $this->assertTrue(UserResource::canDelete($targetAdmin));
    }
}
