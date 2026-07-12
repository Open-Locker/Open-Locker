<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class GroupAccessPickersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function pickerOptions(string $relationManager, string $method, Group $group): array
    {
        $component = Livewire::test($relationManager, [
            'ownerRecord' => $group,
            'pageClass' => EditGroup::class,
        ]);

        $reflection = new ReflectionMethod($relationManager, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($component->instance());
    }

    public function test_compartment_picker_grants_multiple_and_excludes_active_access(): void
    {
        $admin = $this->admin();
        $group = Group::factory()->create();
        $first = Compartment::factory()->create();
        $second = Compartment::factory()->create();
        $alreadyGranted = Compartment::factory()->create();

        app(GroupAccessService::class)->grantCompartmentAccess(
            group: $group,
            compartment: $alreadyGranted,
            actor: $admin,
        );

        $options = $this->pickerOptions(CompartmentAccessesRelationManager::class, 'grantableCompartmentOptions', $group);
        $this->assertArrayHasKey((string) $first->id, $options);
        $this->assertArrayNotHasKey((string) $alreadyGranted->id, $options);

        Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => EditGroup::class,
            ])
            ->callAction(TestAction::make('grantAccess')->table(), data: [
                'compartment_ids' => [(string) $first->id, (string) $second->id],
            ])
            ->assertHasNoActionErrors();

        foreach ([$first, $second] as $compartment) {
            $this->assertTrue(
                GroupCompartmentAccess::query()
                    ->where('group_id', $group->id)
                    ->where('compartment_id', $compartment->id)
                    ->active()
                    ->exists()
            );
        }
    }

    public function test_member_picker_adds_multiple_and_excludes_active_members(): void
    {
        $admin = $this->admin();
        $group = Group::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $existingMember = User::factory()->create();

        app(GroupAccessService::class)->addUser(
            group: $group,
            user: $existingMember,
            actor: $admin,
        );

        $options = $this->pickerOptions(MembersRelationManager::class, 'addableUserOptions', $group);
        $this->assertArrayHasKey((string) $first->id, $options);
        $this->assertArrayNotHasKey((string) $existingMember->id, $options);

        Livewire::actingAs($admin)
            ->test(MembersRelationManager::class, [
                'ownerRecord' => $group,
                'pageClass' => EditGroup::class,
            ])
            ->callAction(TestAction::make('addMember')->table(), data: [
                'user_ids' => [(string) $first->id, (string) $second->id],
            ])
            ->assertHasNoActionErrors();

        $memberIds = $group->fresh()->members->pluck('id')->all();
        $this->assertContains($first->id, $memberIds);
        $this->assertContains($second->id, $memberIds);
    }
}
