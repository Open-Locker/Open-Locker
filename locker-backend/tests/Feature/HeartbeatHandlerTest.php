<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\HeartbeatHandler;
use App\StorableEvents\LockerConnectionRestored;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class HeartbeatHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_valid_heartbeat_updates_locker_state_and_records_restored_event(): void
    {
        $handler = app(HeartbeatHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'connection_status' => 'offline',
            'last_heartbeat_at' => null,
            'connection_status_changed_at' => null,
        ]);

        $timestamp = now()->toIso8601String();

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state",
            (string) json_encode([
                'message_id' => '22222222-2222-2222-2222-222222222222',
                'event' => 'heartbeat',
                'data' => [
                    'timestamp' => $timestamp,
                ],
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('online', $lockerBank->connection_status);
        $this->assertNotNull($lockerBank->last_heartbeat_at);
        $this->assertSame(strtotime($timestamp), strtotime((string) $lockerBank->last_heartbeat_at));

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerConnectionRestored::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame((string) $lockerBank->id, $storedEvent->event_properties['lockerBankUuid'] ?? null);
        $this->assertSame('heartbeat', $storedEvent->event_properties['reason'] ?? null);
    }
}
