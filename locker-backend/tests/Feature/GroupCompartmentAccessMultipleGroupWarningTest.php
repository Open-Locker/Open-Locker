<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\GroupAccessesRelationManager;
use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupCompartmentAccessMultipleGroupWarningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_compartment_grant_group_access_warns_and_can_be_confirmed(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $existing = Group::factory()->create(['name' => 'Existing group']);
        $new = Group::factory()->create(['name' => 'New group']);

        app(GroupAccessService::class)->grantCompartmentAccess(
            group: $existing,
            compartment: $compartment,
            actor: $admin,
        );

        $component = Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['group_ids' => [(string) $new->id]])
            ->assertMountedActionModalSee($existing->name);

        $this->assertFalse(
            GroupCompartmentAccess::query()
                ->where('group_id', $new->id)
                ->where('compartment_id', $compartment->id)
                ->active()
                ->exists()
        );

        $component->callMountedAction()->assertActionNotMounted();

        $this->assertTrue(
            GroupCompartmentAccess::query()
                ->where('group_id', $new->id)
                ->where('compartment_id', $compartment->id)
                ->active()
                ->exists()
        );
    }

    public function test_compartment_grant_group_access_warns_and_can_be_cancelled(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $existing = Group::factory()->create(['name' => 'Existing group']);
        $new = Group::factory()->create(['name' => 'New group']);

        app(GroupAccessService::class)->grantCompartmentAccess(
            group: $existing,
            compartment: $compartment,
            actor: $admin,
        );

        $component = Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['group_ids' => [(string) $new->id]])
            ->assertMountedActionModalSee($existing->name);

        $component->unmountAction()->assertActionNotMounted();

        $this->assertFalse(
            GroupCompartmentAccess::query()
                ->where('group_id', $new->id)
                ->where('compartment_id', $compartment->id)
                ->active()
                ->exists()
        );
    }

    public function test_compartment_grant_group_access_without_existing_group_does_not_require_confirmation(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $group = Group::factory()->create(['name' => 'Only group']);

        Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['group_ids' => [(string) $group->id]])
            ->assertMountedActionModalDontSee(__('Compartment already has group access'))
            ->callMountedAction()
            ->assertActionNotMounted();

        $this->assertTrue(
            GroupCompartmentAccess::query()
                ->where('group_id', $group->id)
                ->where('compartment_id', $compartment->id)
                ->active()
                ->exists()
        );
    }

    public function test_compartment_grant_group_access_warns_when_selecting_multiple_groups_for_empty_compartment(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $first = Group::factory()->create(['name' => 'First group']);
        $second = Group::factory()->create(['name' => 'Second group']);

        $component = Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['group_ids' => [(string) $first->id, (string) $second->id]])
            ->assertMountedActionModalSee($first->name)
            ->assertMountedActionModalSee($second->name);

        $component->callMountedAction()->assertActionNotMounted();

        foreach ([$first, $second] as $group) {
            $this->assertTrue(
                GroupCompartmentAccess::query()
                    ->where('group_id', $group->id)
                    ->where('compartment_id', $compartment->id)
                    ->active()
                    ->exists()
            );
        }
    }

    public function test_group_grant_access_warns_when_compartment_has_other_group_and_can_be_cancelled(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $existing = Group::factory()->create(['name' => 'Existing group']);
        $new = Group::factory()->create(['name' => 'New group']);

        app(GroupAccessService::class)->grantCompartmentAccess(
            group: $existing,
            compartment: $compartment,
            actor: $admin,
        );

        $component = Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $new,
                'pageClass' => EditGroup::class,
            ])
            ->mountAction(TestAction::make('grantAccess')->table())
            ->fillForm(['compartment_ids' => [(string) $compartment->id]])
            ->assertMountedActionModalSee($existing->name);

        $component->unmountAction()->assertActionNotMounted();

        $this->assertFalse(
            GroupCompartmentAccess::query()
                ->where('group_id', $new->id)
                ->where('compartment_id', $compartment->id)
                ->active()
                ->exists()
        );
    }
}
