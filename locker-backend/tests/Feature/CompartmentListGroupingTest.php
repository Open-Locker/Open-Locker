<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ListCompartments;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
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

    private function visibleGroupTitle(mixed $title): string
    {
        if (preg_match('/data-group-name[^>]*>(.*?)</', (string) $title, $matches) === 1) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        $withoutHiddenId = preg_replace('/<span hidden>.*?<\/span>/', '', (string) $title) ?? (string) $title;

        return trim(strip_tags($withoutHiddenId));
    }

    public function test_the_list_is_grouped_by_locker_bank_by_default(): void
    {
        $table = $this->tableFor($this->admin());

        // Asserting the default alone is not enough: getDefaultGroup() synthesises
        // a Group when the id is absent from groups(), so deleting the grouping
        // entirely would still satisfy it.
        $this->assertArrayHasKey('locker_bank_id', $table->getGroups());
        $this->assertSame('locker_bank_id', $table->getDefaultGroup()?->getId());
    }

    public function test_the_bank_group_is_collapsible_so_the_accordion_has_something_to_toggle(): void
    {
        $group = $this->tableFor($this->admin())->getGroups()['locker_bank_id'] ?? null;

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

    public function test_locker_banks_with_the_same_name_stay_in_separate_groups(): void
    {
        $admin = $this->admin();

        $firstBank = LockerBank::factory()->create([
            'name' => 'Main office',
            'location_description' => 'North wing',
        ]);
        $secondBank = LockerBank::factory()->create([
            'name' => 'Main office',
            'location_description' => 'South wing',
        ]);
        $firstCompartment = Compartment::factory()->for($firstBank)->create(['number' => 1]);
        $secondCompartment = Compartment::factory()->for($secondBank)->create(['number' => 1]);

        $group = $this->tableFor($admin)->getDefaultGroup();

        $this->assertNotNull($group);
        $this->assertSame((string) $firstBank->id, $group->getStringKey($firstCompartment));
        $this->assertSame((string) $secondBank->id, $group->getStringKey($secondCompartment));
        $this->assertNotSame($group->getStringKey($firstCompartment), $group->getStringKey($secondCompartment));
        $this->assertSame('Main office', $this->visibleGroupTitle($group->getTitle($firstCompartment)));
        $this->assertSame('Main office', $this->visibleGroupTitle($group->getTitle($secondCompartment)));
        $this->assertNotSame((string) $group->getTitle($firstCompartment), (string) $group->getTitle($secondCompartment));
        $this->assertSame('North wing', $group->getDescription($firstCompartment, $group->getTitle($firstCompartment)));
        $this->assertSame('South wing', $group->getDescription($secondCompartment, $group->getTitle($secondCompartment)));
    }

    public function test_same_named_banks_with_the_same_location_keep_distinct_titles(): void
    {
        $admin = $this->admin();

        $firstBank = LockerBank::factory()->create([
            'name' => 'Main office',
            'location_description' => 'North wing',
        ]);
        $secondBank = LockerBank::factory()->create([
            'name' => 'Main office',
            'location_description' => 'North wing',
        ]);
        $firstCompartment = Compartment::factory()->for($firstBank)->create(['number' => 1]);
        $secondCompartment = Compartment::factory()->for($secondBank)->create(['number' => 1]);

        $group = $this->tableFor($admin)->getDefaultGroup();

        $this->assertNotNull($group);
        $this->assertInstanceOf(HtmlString::class, $group->getTitle($firstCompartment));
        $this->assertSame('North wing', $group->getDescription($firstCompartment, $group->getTitle($firstCompartment)));
        $this->assertSame('North wing', $group->getDescription($secondCompartment, $group->getTitle($secondCompartment)));
        $this->assertNotSame((string) $group->getTitle($firstCompartment), (string) $group->getTitle($secondCompartment));
    }

    public function test_same_named_banks_without_a_location_still_get_distinct_titles(): void
    {
        $admin = $this->admin();

        $firstBank = LockerBank::factory()->create(['name' => 'Main office']);
        $secondBank = LockerBank::factory()->create(['name' => 'Main office']);
        $firstBank->forceFill(['location_description' => null])->save();
        $secondBank->forceFill(['location_description' => null])->save();
        $firstCompartment = Compartment::factory()->for($firstBank)->create();
        $secondCompartment = Compartment::factory()->for($secondBank)->create();

        $group = $this->tableFor($admin)->getDefaultGroup();

        $this->assertNotNull($group);
        $this->assertSame('Main office', $this->visibleGroupTitle($group->getTitle($firstCompartment->unsetRelation('lockerBank'))));
        $this->assertSame('Main office', $this->visibleGroupTitle($group->getTitle($secondCompartment->unsetRelation('lockerBank'))));
        $this->assertNull($group->getDescription($firstCompartment, $group->getTitle($firstCompartment)));
        $this->assertNotSame((string) $group->getTitle($firstCompartment), (string) $group->getTitle($secondCompartment));
    }

    public function test_the_actions_column_has_a_header_label(): void
    {
        $this->assertSame(__('Actions'), $this->tableFor($this->admin())->getRecordActionsColumnLabel());
    }

    public function test_group_titles_show_the_bank_connection_status(): void
    {
        $admin = $this->admin();

        $onlineBank = LockerBank::factory()->create([
            'name' => 'Online bank',
            'connection_status' => 'online',
        ]);
        $offlineBank = LockerBank::factory()->create([
            'name' => 'Offline bank',
            'connection_status' => 'offline',
        ]);
        $onlineCompartment = Compartment::factory()->for($onlineBank)->create();
        $offlineCompartment = Compartment::factory()->for($offlineBank)->create();

        $group = $this->tableFor($admin)->getDefaultGroup();

        $this->assertNotNull($group);
        $this->assertSame('Online bank', $this->visibleGroupTitle($group->getTitle($onlineCompartment)));
        $this->assertSame('Offline bank', $this->visibleGroupTitle($group->getTitle($offlineCompartment)));
        $this->assertStringContainsString(__('online'), (string) $group->getTitle($onlineCompartment));
        $this->assertStringContainsString('fi-color-success', (string) $group->getTitle($onlineCompartment));
        $this->assertStringContainsString(__('offline'), (string) $group->getTitle($offlineCompartment));
        $this->assertStringContainsString('fi-color-danger', (string) $group->getTitle($offlineCompartment));
        $this->assertStringNotContainsString('"', (string) $group->getTitle($onlineCompartment));
        $this->assertStringNotContainsString('"', (string) $group->getTitle($offlineCompartment));
    }
}
