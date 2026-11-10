<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\GroupMembershipsRelationManager;
use App\Models\User;
use App\Services\GroupAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserGroupMembershipsUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_user_resource_registers_group_memberships_relation_manager(): void
    {
        $this->assertContains(
            GroupMembershipsRelationManager::class,
            UserResource::getRelations(),
        );
    }

    public function test_relation_manager_shows_active_memberships_and_hides_revoked(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $service = app(GroupAccessService::class);

        $activeGroup = $service->createGroup('Engineering', actor: $admin);
        $revokedGroup = $service->createGroup('Logistics', actor: $admin);

        $service->addUser(group: $activeGroup, user: $member, actor: $admin);
        $service->addUser(group: $revokedGroup, user: $member, actor: $admin);
        $service->removeUser(group: $revokedGroup, user: $member, actor: $admin);

        Livewire::actingAs($admin)
            ->test(GroupMembershipsRelationManager::class, [
                'ownerRecord' => $member->fresh(),
                'pageClass' => EditUser::class,
            ])
            ->assertCanSeeTableRecords([$activeGroup])
            ->assertCanNotSeeTableRecords([$revokedGroup]);
    }

    public function test_relation_manager_shows_empty_state_when_user_has_no_groups(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(GroupMembershipsRelationManager::class, [
                'ownerRecord' => $member->fresh(),
                'pageClass' => EditUser::class,
            ])
            ->assertCountTableRecords(0)
            ->assertSee(__('filament-tables::table.empty.heading', ['model' => __('Groups')]));
    }

    public function test_relation_manager_hides_memberships_once_they_expire(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();

        $service = app(GroupAccessService::class);

        $permanentGroup = $service->createGroup('Engineering', actor: $admin);
        $expiringGroup = $service->createGroup('Logistics', actor: $admin);

        $service->addUser(group: $permanentGroup, user: $member, actor: $admin);
        $service->addUser(group: $expiringGroup, user: $member, expiresAt: now()->addDay(), actor: $admin);

        Livewire::actingAs($admin)
            ->test(GroupMembershipsRelationManager::class, [
                'ownerRecord' => $member->fresh(),
                'pageClass' => EditUser::class,
            ])
            ->assertCanSeeTableRecords([$permanentGroup, $expiringGroup]);

        $this->travel(2)->days();

        Livewire::actingAs($admin)
            ->test(GroupMembershipsRelationManager::class, [
                'ownerRecord' => $member->fresh(),
                'pageClass' => EditUser::class,
            ])
            ->assertCanSeeTableRecords([$permanentGroup])
            ->assertCanNotSeeTableRecords([$expiringGroup]);
    }
}
