<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ListCompartments;
use App\Models\Compartment;
use App\Models\User;
use App\Services\GroupAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompartmentGroupAccessListTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_names_of_groups_with_active_access(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();
        $empty = Compartment::factory()->create();
        $service = app(GroupAccessService::class);

        $zeta = $service->createGroup('Zeta Seminar', actor: $admin);
        $alpha = $service->createGroup('Alpha Seminar', actor: $admin);
        $service->grantCompartmentAccess($zeta, $compartment, actor: $admin);
        $service->grantCompartmentAccess($alpha, $compartment, actor: $admin);

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->assertTableColumnStateSet('activeGroupAccesses.group.name', ['Alpha Seminar', 'Zeta Seminar'], record: $compartment)
            ->assertTableColumnStateSet('activeGroupAccesses.group.name', null, record: $empty);
    }

    public function test_omits_revoked_expired_and_archived_groups(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();
        $service = app(GroupAccessService::class);

        $active = $service->createGroup('Current Course', actor: $admin);
        $revoked = $service->createGroup('Revoked Course', actor: $admin);
        $expired = $service->createGroup('Expired Course', actor: $admin);
        $archived = $service->createGroup('Archived Course', actor: $admin);

        $service->grantCompartmentAccess($active, $compartment, actor: $admin);
        $service->grantCompartmentAccess($revoked, $compartment, actor: $admin);
        $service->revokeCompartmentAccess($revoked, $compartment, actor: $admin);
        $service->grantCompartmentAccess($expired, $compartment, expiresAt: now()->addMinute(), actor: $admin);
        $service->grantCompartmentAccess($archived, $compartment, actor: $admin);
        $service->archiveGroup($archived, actor: $admin);

        $this->travel(2)->minutes();

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->assertTableColumnStateSet('activeGroupAccesses.group.name', ['Current Course'], record: $compartment);
    }
}
