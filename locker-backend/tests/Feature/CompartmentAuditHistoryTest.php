<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CompartmentResource\Pages\ViewCompartment;
use App\Filament\Resources\CompartmentResource\Widgets\CompartmentAuditHistory;
use App\Models\AuditEvent;
use App\Models\Compartment;
use App\Models\User;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\HeartbeatReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The compartment page shows that compartment's audit history (#370).
 */
class CompartmentAuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_compartment_page_shows_the_history_widget(): void
    {
        $compartment = Compartment::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(ViewCompartment::class, ['record' => $compartment->getKey()])
            ->assertSeeLivewire(CompartmentAuditHistory::class);
    }

    public function test_lists_only_audited_events_of_this_compartment(): void
    {
        $compartment = Compartment::factory()->create();
        $opened = $this->recordEvent(CompartmentOpened::class, $compartment->getKey());
        $otherCompartment = $this->recordEvent(CompartmentOpened::class, (string) Str::uuid());
        $telemetry = $this->recordEvent(HeartbeatReceived::class, $compartment->getKey());

        Livewire::actingAs($this->admin())
            ->test(CompartmentAuditHistory::class, ['record' => $compartment])
            ->assertCanSeeTableRecords([$opened])
            ->assertCanNotSeeTableRecords([$otherCompartment, $telemetry]);
    }

    public function test_hidden_from_users_without_audit_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(CompartmentAuditHistory::canView());
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function recordEvent(string $eventClass, string $compartmentUuid): AuditEvent
    {
        return AuditEvent::create([
            'aggregate_uuid' => (string) Str::uuid(),
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => $eventClass,
            'event_properties' => ['compartmentUuid' => $compartmentUuid],
            'meta_data' => [],
            'created_at' => now(),
        ]);
    }
}
