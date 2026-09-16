<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ADR-0022: a manager may list and view admin accounts but may not mutate them,
 * which includes granting them compartment access. The direct grant was already
 * refused; group membership was not, and a group holding compartment access
 * makes its members' access equal to a direct grant.
 */
class ManagerCannotReachAdminsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function manager(): User
    {
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();

        return $manager;
    }

    private function privateOptions(string $relationManager, string $method, mixed $ownerRecord, string $pageClass): array
    {
        $component = Livewire::test($relationManager, [
            'ownerRecord' => $ownerRecord,
            'pageClass' => $pageClass,
        ]);

        $reflection = new ReflectionMethod($relationManager, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($component->instance());
    }

    public function test_a_manager_is_not_offered_an_admin_in_the_compartment_access_picker(): void
    {
        $manager = $this->manager();
        $targetAdmin = $this->admin();
        $regularUser = User::factory()->create();
        $compartment = Compartment::factory()->create();

        $this->actingAs($manager);

        $options = $this->privateOptions(
            UserAccessesRelationManager::class,
            'grantableUserOptions',
            $compartment,
            ViewCompartment::class,
        );

        $this->assertArrayNotHasKey((string) $targetAdmin->id, $options);
        $this->assertArrayHasKey((string) $regularUser->id, $options);
    }

    public function test_an_admin_is_still_offered_every_user_in_the_compartment_access_picker(): void
    {
        $actingAdmin = $this->admin();
        $targetAdmin = $this->admin();
        $compartment = Compartment::factory()->create();

        $this->actingAs($actingAdmin);

        $options = $this->privateOptions(
            UserAccessesRelationManager::class,
            'grantableUserOptions',
            $compartment,
            ViewCompartment::class,
        );

        $this->assertArrayHasKey((string) $targetAdmin->id, $options);
    }

    public function test_a_manager_is_not_offered_an_admin_in_the_group_members_picker(): void
    {
        $manager = $this->manager();
        $targetAdmin = $this->admin();
        $regularUser = User::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($manager);

        $options = $this->privateOptions(
            MembersRelationManager::class,
            'addableUserOptions',
            $group,
            EditGroup::class,
        );

        $this->assertArrayNotHasKey((string) $targetAdmin->id, $options);
        $this->assertArrayHasKey((string) $regularUser->id, $options);
    }

    public function test_a_manager_cannot_add_an_admin_to_a_group(): void
    {
        $manager = $this->manager();
        $targetAdmin = $this->admin();
        $group = Group::factory()->create();

        // The picker hides it, but the service is what actually protects this:
        // a group holding compartment access would otherwise hand an admin the
        // access a direct grant refuses.
        $this->expectException(AuthorizationException::class);

        app(GroupAccessService::class)->addUser($group, $targetAdmin, null, $manager);
    }

    public function test_an_admin_can_still_add_an_admin_to_a_group(): void
    {
        $actingAdmin = $this->admin();
        $targetAdmin = $this->admin();
        $group = Group::factory()->create();

        app(GroupAccessService::class)->addUser($group, $targetAdmin, null, $actingAdmin);

        $this->assertTrue($group->activeMembers()->whereKey($targetAdmin->id)->exists());
    }

    public function test_a_manager_can_still_add_a_regular_user_to_a_group(): void
    {
        $manager = $this->manager();
        $regularUser = User::factory()->create();
        $group = Group::factory()->create();

        app(GroupAccessService::class)->addUser($group, $regularUser, null, $manager);

        $this->assertTrue($group->activeMembers()->whereKey($regularUser->id)->exists());
    }
}
