<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ListCompartments;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The compartment list navigates by locker bank rather than by paging: banks
 * arrive collapsed and the admin opens one. Nothing else asserts that shape, so
 * re-enabling pagination or dropping the grouping to "fix" a slow page would
 * otherwise be a silent change rather than a deliberate one.
 */
class CompartmentListGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function tableFor(User $admin): Table
    {
        return Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->instance()
            ->getTable();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_the_list_is_grouped_by_locker_bank_by_default(): void
    {
        $table = $this->tableFor($this->admin());

        // Asserting the default alone is not enough: getDefaultGroup() synthesises
        // a Group when the id is absent from groups(), so deleting the grouping
        // entirely would still satisfy it.
        $this->assertArrayHasKey('lockerBank.name', $table->getGroups());
        $this->assertSame('lockerBank.name', $table->getDefaultGroup()?->getId());
    }

    public function test_the_bank_group_is_collapsible_so_the_accordion_has_something_to_toggle(): void
    {
        $group = $this->tableFor($this->admin())->getGroups()['lockerBank.name'] ?? null;

        // Group::$isCollapsible defaults to false, and Filament gates both the
        // toggle handler and the fi-collapsed binding on it. Without it the
        // headers never get .fi-collapsible, the accordion script matches
        // nothing, and the page degrades to the flat list #167 removed.
        $this->assertNotNull($group);
        $this->assertTrue($group->isCollapsible());
    }

    public function test_groups_start_collapsed_so_the_page_opens_as_a_short_list_of_banks(): void
    {
        $this->assertTrue($this->tableFor($this->admin())->areGroupsCollapsedByDefault());
    }

    public function test_pagination_is_off_so_a_bank_is_never_split_across_pages(): void
    {
        $this->assertFalse($this->tableFor($this->admin())->isPaginated());
    }

    public function test_every_compartment_of_every_bank_is_listed_without_paging(): void
    {
        $admin = $this->admin();

        $compartments = collect();
        foreach (LockerBank::factory()->count(2)->create() as $bankIndex => $bank) {
            for ($i = 1; $i <= 3; $i++) {
                $compartments->push(Compartment::factory()->create([
                    'locker_bank_id' => $bank->id,
                    'number' => ($bankIndex * 10) + $i,
                ]));
            }
        }

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->assertCanSeeTableRecords($compartments);
    }
}
