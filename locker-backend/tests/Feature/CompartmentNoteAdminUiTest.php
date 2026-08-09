<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentsRelationManager;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\CompartmentService;
use App\StorableEvents\CompartmentContentNoteUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompartmentNoteAdminUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_compartments_relation_manager_shows_content_note_column(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        Compartment::factory()->for($lockerBank)->create([
            'content_note' => 'Winter tires (set of 4)',
        ]);

        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->assertSuccessful()
            ->assertSee('Winter tires (set of 4)');
    }

    public function test_note_history_modal_lists_event_sourced_changes(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create();

        // Two real, event-sourced edits so the history has entries with an actor.
        $service = app(CompartmentService::class);
        $service->updateContentNote($admin, $compartment, 'First note');
        $service->updateContentNote($admin, $compartment, 'Second note');

        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->callTableColumnAction('content_note', $compartment->getKey())
            ->assertSuccessful();
    }

    public function test_admin_can_edit_a_content_note_through_the_event_sourced_path(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create(['content_note' => null]);

        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->callTableAction('editContentNote', $compartment->getKey(), ['note' => 'Snow chains'])
            ->assertHasNoTableActionErrors();

        $compartment->refresh();
        $this->assertSame('Snow chains', $compartment->content_note);
        $this->assertSame($admin->id, $compartment->content_note_updated_by_user_id);

        // The read model must be reached through the event, not written directly.
        $this->assertDatabaseHas('stored_events', [
            'aggregate_uuid' => $compartment->id,
            'event_class' => CompartmentContentNoteUpdated::class,
        ]);
    }

    public function test_admin_can_clear_a_content_note(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create();

        app(CompartmentService::class)->updateContentNote($admin, $compartment, 'To be cleared');

        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->callTableAction('editContentNote', $compartment->getKey(), ['note' => '   '])
            ->assertHasNoTableActionErrors();

        $this->assertNull($compartment->refresh()->content_note);
    }

    public function test_the_edit_action_is_prefilled_with_the_current_note(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create(['content_note' => 'Existing note']);

        Livewire::actingAs($admin)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->mountTableAction('editContentNote', $compartment->getKey())
            ->assertTableActionDataSet(['note' => 'Existing note']);
    }

    public function test_a_plain_user_is_not_offered_the_edit_action(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create();

        Livewire::actingAs(User::factory()->create())
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->assertTableActionHidden('editContentNote', $compartment->getKey());
    }

    public function test_a_manager_may_edit_notes_because_the_service_allows_it(): void
    {
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();

        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create();

        Livewire::actingAs($manager)
            ->test(CompartmentsRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBank::class,
            ])
            ->callTableAction('editContentNote', $compartment->getKey(), ['note' => 'Checked by manager'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Checked by manager', $compartment->refresh()->content_note);
    }
}
