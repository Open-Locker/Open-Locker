<?php

namespace Tests\Feature;

use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\StorableEvents\CommandResponseReceived;
use App\StorableEvents\CompartmentOpenAcknowledged;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CommandResponseDerivationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A successful command response means the unlock pulse was sent, not that
     * the door opened. It must derive acknowledgement only — the physical
     * outcome arrives separately on the event channel.
     */
    public function test_open_compartment_response_derives_acknowledgement_not_opened(): void
    {
        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $transactionId = '22222222-2222-2222-2222-222222222222';
        $compartmentUuid = '33333333-3333-3333-3333-333333333333';

        $this->mock(OpenCompartmentCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });

        event(new CompartmentOpeningRequested(
            lockerBankUuid: $lockerUuid,
            compartmentUuid: $compartmentUuid,
            compartmentNumber: 7,
            commandId: $transactionId,
        ));

        event(new CommandResponseReceived(
            lockerBankUuid: $lockerUuid,
            transactionId: $transactionId,
            action: 'open_compartment',
            result: 'success',
            timestamp: now()->toIso8601String(),
        ));

        $this->assertSame(1, $this->derivedCount(CompartmentOpenAcknowledged::class, $transactionId));
        $this->assertSame(
            0,
            $this->derivedCount(CompartmentOpened::class, $transactionId),
            'A pulse acknowledgement must not be recorded as the door having opened.'
        );
    }

    public function test_acknowledgement_derivation_is_idempotent(): void
    {
        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $transactionId = '44444444-4444-4444-4444-444444444444';

        $this->mock(OpenCompartmentCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });

        event(new CompartmentOpeningRequested(
            lockerBankUuid: $lockerUuid,
            compartmentUuid: '55555555-5555-5555-5555-555555555555',
            compartmentNumber: 3,
            commandId: $transactionId,
        ));

        foreach (range(1, 2) as $ignored) {
            event(new CommandResponseReceived(
                lockerBankUuid: $lockerUuid,
                transactionId: $transactionId,
                action: 'open_compartment',
                result: 'success',
                timestamp: now()->toIso8601String(),
            ));
        }

        $this->assertSame(1, $this->derivedCount(CompartmentOpenAcknowledged::class, $transactionId));
    }

    private function derivedCount(string $eventClass, string $transactionId): int
    {
        return EloquentStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where('event_properties->transactionId', $transactionId)
            ->count();
    }
}
