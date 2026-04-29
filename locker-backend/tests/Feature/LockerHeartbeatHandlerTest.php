<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mqtt\Handlers\LockerHeartbeatHandler;
use App\StorableEvents\LockerConnectionRestored;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class LockerHeartbeatHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_valid_payload_on_state_heartbeat_topic_updates_locker_bank(): void
    {
        $handler = app(LockerHeartbeatHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'connection_status' => 'offline',
            'last_heartbeat_at' => null,
            'connection_status_changed_at' => null,
        ]);

        $timestamp = now()->toIso8601String();

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/heartbeat",
            (string) json_encode([
                'message_id' => '22222222-2222-2222-2222-222222222222',
                'timestamp' => $timestamp,
                'uptime_seconds' => 30,
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

    public function test_legacy_multiplexed_state_topic_with_event_alias_is_not_processed(): void
    {
        $handler = app(LockerHeartbeatHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'connection_status' => 'offline',
            'last_heartbeat_at' => null,
        ]);

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state",
            (string) json_encode([
                'message_id' => '22222222-2222-2222-2222-222222222222',
                'event' => 'heartbeat',
                'data' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame('offline', $lockerBank->connection_status);
        $this->assertNull($lockerBank->last_heartbeat_at);
    }

    public function test_duplicate_message_id_is_ignored(): void
    {
        $handler = app(LockerHeartbeatHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '88888888-8888-8888-8888-888888888888',
            'connection_status' => 'online',
        ]);

        $firstTs = now()->subHour()->toIso8601String();
        $secondTs = now()->toIso8601String();

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/heartbeat",
            (string) json_encode([
                'message_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'timestamp' => $firstTs,
                'uptime_seconds' => 1,
            ]),
        );

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state/heartbeat",
            (string) json_encode([
                'message_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'timestamp' => $secondTs,
                'uptime_seconds' => 2,
            ]),
        );

        $lockerBank->refresh();

        $this->assertSame(strtotime($firstTs), strtotime((string) $lockerBank->last_heartbeat_at));
    }
}
