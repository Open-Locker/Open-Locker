<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompartmentAccessesRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_grant_access_assigns_multiple_compartments_in_one_action(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $lockerBank = LockerBank::factory()->create();
        $first = Compartment::factory()->for($lockerBank)->create(['number' => 1]);
        $second = Compartment::factory()->for($lockerBank)->create(['number' => 2]);

        Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => EditUser::class,
            ])
            ->callAction(TestAction::make('grantAccess')->table(), data: [
                'compartment_ids' => [(string) $first->id, (string) $second->id],
            ])
            ->assertHasNoActionErrors();

        $service = app(CompartmentAccessService::class);
        $this->assertTrue($service->hasActiveAccess($user, $first));
        $this->assertTrue($service->hasActiveAccess($user, $second));
    }

    public function test_grant_picker_excludes_compartments_with_active_access(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $lockerBank = LockerBank::factory()->create();
        $alreadyGranted = Compartment::factory()->for($lockerBank)->create(['number' => 1]);
        $available = Compartment::factory()->for($lockerBank)->create(['number' => 2]);

        app(CompartmentAccessService::class)->grantAccess(
            user: $user,
            compartment: $alreadyGranted,
            actor: $admin,
        );

        $component = Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => EditUser::class,
            ]);

        $reflection = new \ReflectionMethod(CompartmentAccessesRelationManager::class, 'grantableCompartmentOptions');
        $reflection->setAccessible(true);
        $options = $reflection->invoke($component->instance());

        $this->assertArrayHasKey((string) $available->id, $options);
        $this->assertArrayNotHasKey((string) $alreadyGranted->id, $options);
    }

    public function test_grant_picker_sorts_by_locker_bank_then_compartment_number(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $bankB = LockerBank::factory()->create(['name' => 'Bravo']);
        $bankA = LockerBank::factory()->create(['name' => 'Alpha']);
        $bravo2 = Compartment::factory()->for($bankB)->create(['number' => 2]);
        $bravo1 = Compartment::factory()->for($bankB)->create(['number' => 1]);
        $alpha1 = Compartment::factory()->for($bankA)->create(['number' => 1]);

        $component = Livewire::actingAs($admin)
            ->test(CompartmentAccessesRelationManager::class, [
                'ownerRecord' => $user,
                'pageClass' => EditUser::class,
            ]);

        $reflection = new \ReflectionMethod(CompartmentAccessesRelationManager::class, 'grantableCompartmentOptions');
        $reflection->setAccessible(true);
        $options = $reflection->invoke($component->instance());

        $this->assertSame([
            (string) $alpha1->id,
            (string) $bravo1->id,
            (string) $bravo2->id,
        ], array_keys($options));
    }
}
