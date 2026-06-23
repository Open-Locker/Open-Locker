<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\GroupAccessService;
use App\StorableEvents\CompartmentOpenAuthorized;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class ManagerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(): User
    {
        $this->seed(AuthorizationSeeder::class);
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

        $response = $this->actingAs($manager)->get(route('filament.admin.pages.dashboard'));

        $response->assertSuccessful();
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

    public function test_manager_cannot_manage_groups(): void
    {
        $manager = $this->makeManager();

        $this->assertFalse($manager->can(Permission::RolesManage->value));

        $this->expectException(AuthorizationException::class);

        // Group management remains admin-only (outside #95's manager scope).
        app(GroupAccessService::class)->createGroup('Crew', actor: $manager);
    }

    public function test_manager_cannot_configure_locker_banks_or_manage_roles(): void
    {
        $manager = $this->makeManager();

        $this->assertFalse($manager->can(Permission::LockerBankConfigure->value));
        $this->assertFalse($manager->can(Permission::RolesManage->value));
        $this->assertFalse($manager->can(Permission::SystemConfigure->value));
        $this->assertFalse($manager->isAdmin());
    }

    public function test_regular_user_cannot_access_panel(): void
    {
        $user = $this->makeRegularUser();

        $response = $this->actingAs($user)->get(route('filament.admin.pages.dashboard'));

        $response->assertForbidden();
    }

    public function test_manager_sees_only_permitted_filament_resources(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager);

        $this->assertTrue(\App\Filament\Resources\UserResource::canAccess());
        $this->assertFalse(\App\Filament\Resources\LockerBankResource::canAccess());
        $this->assertFalse(\App\Filament\Resources\GroupResource::canAccess());
        $this->assertFalse(\App\Filament\Resources\TermsDocumentVersionResource::canAccess());
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
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create(); // bootstrap admin
        $target = User::factory()->create();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('manageRoles', data: ['roles' => [Role::Manager->value]])
            ->assertHasNoActionErrors();

        $this->assertTrue($target->fresh()->hasRole(Role::Manager->value));
        $this->assertTrue($target->fresh()->can(Permission::CompartmentOpen->value));
    }

    public function test_manager_sees_grant_and_revoke_access_actions_on_a_user(): void
    {
        $manager = $this->makeManager();
        $target = User::factory()->create();

        \Livewire\Livewire::actingAs($manager)
            ->test(\App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $target,
                'pageClass' => \App\Filament\Resources\UserResource\Pages\EditUser::class,
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
                'pageClass' => \App\Filament\Resources\UserResource\Pages\EditUser::class,
            ])
            ->assertDontSee(__('Grant access'));
    }

    public function test_manager_cannot_see_role_management_action(): void
    {
        $this->seed(AuthorizationSeeder::class);
        User::factory()->create(); // bootstrap admin
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();

        \Livewire\Livewire::actingAs($manager)
            ->test(\App\Filament\Resources\UserResource\Pages\EditUser::class, ['record' => $manager->getRouteKey()])
            ->assertActionHidden('manageRoles')
            ->assertActionHidden('setAsAdmin');
    }
}
