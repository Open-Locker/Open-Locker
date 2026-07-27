<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ListCompartments;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompartmentDirectUsersCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_users_column_shows_count_of_active_direct_accesses(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();
        $service = app(CompartmentAccessService::class);

        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            $service->grantAccess(user: $user, compartment: $compartment, actor: $admin);
        }

        $revokedUser = User::factory()->create();
        $service->grantAccess(user: $revokedUser, compartment: $compartment, actor: $admin);
        $service->revokeAccess(user: $revokedUser, compartment: $compartment, actor: $admin);

        $expiredUser = User::factory()->create();
        $service->grantAccess(
            user: $expiredUser,
            compartment: $compartment,
            expiresAt: now()->addMinute(),
            actor: $admin,
        );
        $this->travel(2)->minutes();

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->assertTableColumnStateSet('active_accesses_count', 3, record: $compartment);
    }

    public function test_direct_users_column_shows_zero_when_no_grants_exist(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->assertTableColumnStateSet('active_accesses_count', 0, record: $compartment);
    }
}
