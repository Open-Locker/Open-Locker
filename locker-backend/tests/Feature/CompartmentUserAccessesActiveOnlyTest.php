<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompartmentUserAccessesActiveOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_shows_only_active_grants_and_hides_revoked_and_expired(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();
        $activeUser = User::factory()->create();
        $revokedUser = User::factory()->create();
        $expiredUser = User::factory()->create();

        $service = app(CompartmentAccessService::class);

        $service->grantAccess(user: $activeUser, compartment: $compartment, actor: $admin);

        $service->grantAccess(user: $revokedUser, compartment: $compartment, actor: $admin);
        $service->revokeAccess(user: $revokedUser, compartment: $compartment, actor: $admin);

        $service->grantAccess(
            user: $expiredUser,
            compartment: $compartment,
            expiresAt: now()->addMinute(),
            actor: $admin,
        );
        $this->travel(2)->minutes();

        $accesses = CompartmentAccess::query()
            ->where('compartment_id', $compartment->id)
            ->get()
            ->keyBy('user_id');

        Livewire::actingAs($admin)
            ->test(UserAccessesRelationManager::class, [
                'ownerRecord' => $compartment,
                'pageClass' => ViewCompartment::class,
            ])
            ->assertCanSeeTableRecords([$accesses[$activeUser->id]])
            ->assertCanNotSeeTableRecords([
                $accesses[$revokedUser->id],
                $accesses[$expiredUser->id],
            ]);
    }
}
