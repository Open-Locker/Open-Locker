<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\GroupAccessesRelationManager;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\GroupAccessService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class CompartmentAccessPickersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function pickerOptions(string $relationManager, string $method, Compartment $compartment): array
    {
        $component = Livewire::test($relationManager, [
            'ownerRecord' => $compartment,
            'pageClass' => ViewCompartment::class,
        ]);

        $reflection = new ReflectionMethod($relationManager, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($component->instance());
    }

    public function test_user_picker_grants_multiple_users_and_excludes_active_access(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $alreadyGranted = User::factory()->create();

        app(CompartmentAccessService::class)->grantAccess(
            user: $alreadyGranted,
            compartment: $compartment,
            actor: $admin,
        );

        $options = $this->pickerOptions(UserAccessesRelationManager::class, 'grantableUserOptions', $compartment);
        $this->assertArrayHasKey((string) $first->id, $options);
        $this->assertArrayNotHasKey((string) $alreadyGranted->id, $options);

        Livewire::actingAs($admin)
            ->test(UserAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->callAction(TestAction::make('grantAccess')->table(), data: [
                'user_ids' => [(string) $first->id, (string) $second->id],
            ])
            ->assertHasNoActionErrors();

        $service = app(CompartmentAccessService::class);
        $this->assertTrue($service->hasActiveAccess($first, $compartment));
        $this->assertTrue($service->hasActiveAccess($second, $compartment));
    }

    public function test_group_picker_grants_multiple_groups_and_excludes_active_access(): void
    {
        $admin = $this->admin();
        $compartment = Compartment::factory()->create();
        $first = Group::factory()->create();
        $second = Group::factory()->create();
        $alreadyGranted = Group::factory()->create();

        app(GroupAccessService::class)->grantCompartmentAccess(
            group: $alreadyGranted,
            compartment: $compartment,
            actor: $admin,
        );

        $options = $this->pickerOptions(GroupAccessesRelationManager::class, 'grantableGroupOptions', $compartment);
        $this->assertArrayHasKey((string) $first->id, $options);
        $this->assertArrayNotHasKey((string) $alreadyGranted->id, $options);

        Livewire::actingAs($admin)
            ->test(GroupAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->callAction(TestAction::make('grantAccess')->table(), data: [
                'group_ids' => [(string) $first->id, (string) $second->id],
            ])
            ->assertHasNoActionErrors();

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
}
