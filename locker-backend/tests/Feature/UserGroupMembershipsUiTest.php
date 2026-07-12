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
}
