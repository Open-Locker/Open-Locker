<?php

namespace Tests\Feature;

use App\StorableEvents\CommandResponseReceived;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CommandResponseDerivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_compartment_response_derives_compartment_opened_event(): void
    {
        $lockerUuid = '11111111-1111-1111-1111-111111111111';
        $transactionId = '22222222-2222-2222-2222-222222222222';
        $compartmentUuid = '33333333-3333-3333-3333-333333333333';

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

        $derivedCount = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpened::class)
            ->where('event_properties->transactionId', $transactionId)
            ->count();

        $this->assertSame(1, $derivedCount);
    }
}
