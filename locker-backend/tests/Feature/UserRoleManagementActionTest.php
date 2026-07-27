<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleManagementActionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function grantRole(User $user, Role $role): void
    {
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($user->id))
            ->grantRole($user->id, $role->value, null, now())
            ->persist();
        $user->flushPermissionCache();
    }

    public function test_admin_can_promote_user_to_manager_and_admin_and_demote_back(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $component = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()]);

        $component->callAction('changeRole', data: ['role' => Role::Manager->value])
            ->assertHasNoActionErrors();
        $this->assertSame([Role::Manager->value], $target->fresh()->roleNames());

        $component->callAction('changeRole', data: ['role' => Role::Admin->value])
            ->assertHasNoActionErrors();
        $this->assertSame([Role::Admin->value], $target->fresh()->roleNames());

        $component->callAction('changeRole', data: ['role' => Role::User->value])
            ->assertHasNoActionErrors();
        $this->assertSame([], $target->fresh()->roleNames());
    }

    public function test_current_role_is_preselected(): void
    {
        $admin = $this->admin();
        $manager = User::factory()->create();
        $this->grantRole($manager, Role::Manager);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $manager->getRouteKey()])
            ->mountAction('changeRole')
            ->assertActionDataSet(['role' => Role::Manager->value]);
    }

    public function test_legacy_multi_role_user_is_normalized_to_the_selected_role(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->grantRole($target, Role::Manager);
        $this->grantRole($target, Role::Admin);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('changeRole', data: ['role' => Role::Manager->value])
            ->assertHasNoActionErrors();

        $this->assertSame([Role::Manager->value], $target->fresh()->roleNames());
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->callAction('changeRole', data: ['role' => Role::User->value])
            ->assertNotified(__('Action cancelled'));

        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
