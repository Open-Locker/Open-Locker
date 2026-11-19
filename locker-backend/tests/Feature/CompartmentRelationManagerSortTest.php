<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\GroupResource\Pages\EditGroup;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager as GroupCompartmentAccessesRelationManager;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentsRelationManager;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager as UserCompartmentAccessesRelationManager;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\GroupAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compartments listed inside relation managers read in numeric order (#382),
 * regardless of the order they were created or granted in.
 */
class CompartmentRelationManagerSortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LockerBank $lockerBank;

    /** @var list<Compartment> compartments created out of numeric order */
    private array $compartments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->makeAdmin();
        $this->lockerBank = LockerBank::factory()->create();

        foreach ([12, 2, 11, 1, 3] as $number) {
            $this->compartments[] = Compartment::factory()->for($this->lockerBank)->create(['number' => $number]);
        }
    }

    public function test_user_compartment_accesses_are_sorted_by_compartment_number(): void
    {
        $user = User::factory()->create();
        foreach ($this->compartments as $compartment) {
            app(CompartmentAccessService::class)->grantAccess(user: $user, compartment: $compartment, actor: $this->admin);
        }

        Livewire::actingAs($this->admin)
            ->test(UserCompartmentAccessesRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->assertCanSeeTableRecords($user->compartmentAccesses()->with('compartment')->get()->sortBy('compartment.number'), inOrder: true);
    }

    public function test_group_compartment_accesses_are_sorted_by_compartment_number(): void
    {
        $group = Group::factory()->create();
        foreach ($this->compartments as $compartment) {
            app(GroupAccessService::class)->grantCompartmentAccess($group, $compartment, actor: $this->admin);
        }

        Livewire::actingAs($this->admin)
            ->test(GroupCompartmentAccessesRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditGroup::class])
            ->assertCanSeeTableRecords($group->compartmentAccesses()->with('compartment')->get()->sortBy('compartment.number'), inOrder: true);
    }

    public function test_locker_bank_compartments_are_sorted_by_number(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CompartmentsRelationManager::class, ['ownerRecord' => $this->lockerBank, 'pageClass' => EditLockerBank::class])
            ->assertCanSeeTableRecords(collect($this->compartments)->sortBy('number'), inOrder: true);
    }
}
