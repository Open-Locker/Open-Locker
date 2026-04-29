<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CompartmentDoorState;
use App\Models\Compartment;
use App\Mqtt\Handlers\CompartmentSnapshotHandler;
use App\StorableEvents\CompartmentDoorStateChanged;
use App\StorableEvents\CompartmentStateChangesApplied;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentSnapshotHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_snapshot_updates_compartments_and_locker_bank_timestamp_via_projections(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '44444444-4444-4444-4444-444444444444',
        ]);

        $compartmentOne = Compartment::factory()->for($lockerBank)->create([
            'number' => 1,
            'slave_id' => 1,
            'address' => 0,
        ]);
        $compartmentTwo = Compartment::factory()->for($lockerBank)->create([
            'number' => 2,
            'slave_id' => 1,
            'address' => 1,
        ]);

        $handler = app(CompartmentSnapshotHandler::class);
        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/compartments",
            (string) json_encode([
                'message_id' => '33333333-3333-3333-3333-333333333333',
                'timestamp' => '2026-04-14T19:33:05Z',
                'compartments' => [
                    ['compartment_number' => 1, 'door_state' => 'closed'],
                    ['compartment_number' => 2, 'door_state' => 'open'],
                ],
            ]),
        );

        $compartmentOne->refresh();
        $compartmentTwo->refresh();
        $lockerBank->refresh();

        $this->assertSame(CompartmentDoorState::Closed, $compartmentOne->door_state);
        $this->assertSame(CompartmentDoorState::Open, $compartmentTwo->door_state);
        $this->assertNotNull($lockerBank->last_compartment_state_change_at);

        $this->assertSame(
            2,
            EloquentStoredEvent::query()->where('event_class', CompartmentDoorStateChanged::class)->count(),
        );
        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', CompartmentStateChangesApplied::class)->count(),
        );
    }

    public function test_same_message_id_can_apply_updated_snapshot_without_duplicate_blocking(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '77777777-7777-7777-7777-777777777777',
        ]);

        $compartment = Compartment::factory()->for($lockerBank)->create([
            'number' => 1,
            'slave_id' => 1,
            'address' => 0,
            'door_state' => CompartmentDoorState::Closed,
        ]);

        $handler = app(CompartmentSnapshotHandler::class);
        $messageId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/compartments",
            (string) json_encode([
                'message_id' => $messageId,
                'timestamp' => '2026-04-14T10:00:00Z',
                'compartments' => [
                    ['compartment_number' => 1, 'door_state' => 'closed'],
                ],
            ]),
        );

        $this->assertSame(
            0,
            EloquentStoredEvent::query()->where('event_class', CompartmentDoorStateChanged::class)->count(),
            'No-op snapshot must not record domain events',
        );

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/compartments",
            (string) json_encode([
                'message_id' => $messageId,
                'timestamp' => '2026-04-14T10:00:00Z',
                'compartments' => [
                    ['compartment_number' => 1, 'door_state' => 'open'],
                ],
            ]),
        );

        $compartment->refresh();
        $this->assertSame(CompartmentDoorState::Open, $compartment->door_state);

        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', CompartmentDoorStateChanged::class)->count(),
        );
        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', CompartmentStateChangesApplied::class)->count(),
        );
    }

    public function test_identical_snapshot_after_projection_records_no_additional_stored_events(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '66666666-6666-6666-6666-666666666666',
        ]);

        Compartment::factory()->for($lockerBank)->create([
            'number' => 1,
            'slave_id' => 1,
            'address' => 0,
        ]);

        $handler = app(CompartmentSnapshotHandler::class);
        $payload = [
            'message_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'timestamp' => '2026-04-14T12:00:00Z',
            'compartments' => [
                ['compartment_number' => 1, 'door_state' => 'closed'],
            ],
        ];

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/compartments",
            (string) json_encode($payload),
        );

        $afterFirst = EloquentStoredEvent::query()->count();

        $payload['message_id'] = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/compartments",
            (string) json_encode($payload),
        );

        $this->assertSame($afterFirst, EloquentStoredEvent::query()->count());
    }
}
