<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentsRelationManager;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\CompartmentService;
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
}
