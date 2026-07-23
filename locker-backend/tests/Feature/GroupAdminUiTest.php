<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\GroupResource;
use App\Filament\Resources\GroupResource\Pages\CreateGroup;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

class GroupAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_group_resource_table_builds(): void
    {
        $livewire = $this->createMock(HasTable::class);

        $this->assertInstanceOf(Table::class, GroupResource::table(Table::make($livewire)));
    }

    public function test_admin_can_load_group_list_and_create_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(GroupResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(GroupResource::getUrl('create'))->assertOk();
    }

    public function test_admin_can_load_group_edit_page(): void
    {
        $admin = $this->admin();
        $group = app(GroupAccessService::class)->createGroup('Engineering', actor: $admin);

        $this->actingAs($admin)
            ->get(GroupResource::getUrl('edit', ['record' => $group->id]))
            ->assertOk();
    }

    public function test_non_admin_cannot_load_group_list(): void
    {
        User::factory()->create();
        $user = User::factory()->create();
        $user->removeAdmin();

        $this->actingAs($user)->get(GroupResource::getUrl('index'))->assertForbidden();
    }

    public function test_create_page_persists_group_through_service(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateGroup::class)
            ->fillForm([
                'name' => 'Logistics',
                'description' => 'Warehouse crew',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            // Projectors run synchronously (#128), so the group read model exists
            // immediately after creation and we can redirect straight to its edit
            // page (to add members / grant access) instead of back to the list.
            ->assertRedirect(GroupResource::getUrl('edit', [
                'record' => Group::where('name', 'Logistics')->sole(),
            ]));

        $this->assertDatabaseHas('groups', [
            'name' => 'Logistics',
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_group_resource_registers_member_and_compartment_relation_managers(): void
    {
        $relations = GroupResource::getRelations();

        $this->assertContains(MembersRelationManager::class, $relations);
        $this->assertContains(CompartmentAccessesRelationManager::class, $relations);
    }

    public function test_relation_managers_can_be_instantiated(): void
    {
        // Smoke: the relation manager classes resolve and declare their relationship.
        $this->assertSame('members', $this->relationshipOf(MembersRelationManager::class));
        $this->assertSame('compartmentAccesses', $this->relationshipOf(CompartmentAccessesRelationManager::class));
    }

    private function relationshipOf(string $relationManager): string
    {
        $property = (new ReflectionClass($relationManager))->getProperty('relationship');
        $property->setAccessible(true);

        return (string) $property->getValue();
    }

    public function test_edit_group_page_has_archive_action_but_no_hard_delete(): void
    {
        // Groups are archived, not hard-deleted. See ADR-0020 / #106.
        $page = app(EditGroup::class);
        $method = (new ReflectionClass($page))->getMethod('getHeaderActions');
        $method->setAccessible(true);

        $actionNames = collect($method->invoke($page))
            ->map(fn (Action $action): string => $action->getName())
            ->all();

        $this->assertNotContains('delete', $actionNames);
        $this->assertContains('archive', $actionNames);
    }

    public function test_admin_can_archive_group_through_edit_page(): void
    {
        $admin = $this->admin();
        $group = app(GroupAccessService::class)->createGroup('Logistics', actor: $admin);

        Livewire::actingAs($admin)
            ->test(EditGroup::class, ['record' => $group->id])
            ->callAction('archive');

        $this->assertNotNull(Group::find($group->id)->archived_at);
    }

    public function test_group_list_hides_archived_groups_by_default_and_shows_with_filter(): void
    {
        $admin = $this->admin();
        $service = app(GroupAccessService::class);

        $active = Group::find($service->createGroup('Active', actor: $admin)->id);
        $archived = Group::find($service->createGroup('Archived', actor: $admin)->id);
        $service->archiveGroup($archived, actor: $admin);

        Livewire::actingAs($admin)
            ->test(ListGroups::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$archived])
            ->filterTable('archived_at', null)
            ->assertCanSeeTableRecords([$active, $archived]);
    }
}
