<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\DeviceEventHandler;
use App\StorableEvents\DeviceEventReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class DeviceEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_valid_device_event_records_expected_event_payload(): void
    {
        $handler = app(DeviceEventHandler::class);
        $handler->handleMessage('locker/11111111-1111-1111-1111-111111111111/event', (string) json_encode([
            'message_id' => '33333333-3333-3333-3333-333333333333',
            'event' => 'door_opened',
            'event_id' => '44444444-4444-4444-4444-444444444444',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'compartment_id' => 7,
                'source' => 'sensor',
            ],
        ]));

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', DeviceEventReceived::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $storedEvent->event_properties['lockerBankUuid'] ?? null);
        $this->assertSame('door_opened', $storedEvent->event_properties['event'] ?? null);
        $this->assertSame('44444444-4444-4444-4444-444444444444', $storedEvent->event_properties['eventId'] ?? null);
        $this->assertSame(7, $storedEvent->event_properties['data']['compartment_id'] ?? null);
        $this->assertSame('sensor', $storedEvent->event_properties['data']['source'] ?? null);
    }
}
