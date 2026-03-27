<?php

namespace Tests\Feature;

use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Handlers\HeartbeatHandler;
use App\Mqtt\Handlers\RegistrationHandler;
use App\StorableEvents\DeviceEventReceived;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class InboundMqttHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_duplicate_message_id_is_deduplicated_for_device_events(): void
    {
        $handler = app(DeviceEventHandler::class);
        $topic = 'locker/11111111-1111-1111-1111-111111111111/event';
        $payload = [
            'message_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'event' => 'door_opened',
            'timestamp' => now()->toIso8601String(),
            'data' => ['compartment_id' => 1],
        ];

        $message = (string) json_encode($payload);

        $handler->handleMessage($topic, $message);
        $handler->handleMessage($topic, $message);

        $storedCount = EloquentStoredEvent::query()
            ->where('event_class', DeviceEventReceived::class)
            ->count();

        $this->assertSame(1, $storedCount);
    }

    public function test_missing_message_id_is_rejected_for_registration(): void
    {
        $handler = app(RegistrationHandler::class);
        $lockerBank = LockerBankFactory::new()->create();

        $topic = sprintf('locker/register/%s', $lockerBank->provisioning_token);
        $payload = [
            'client_id' => 'prov-client-1',
        ];

        $handler->handleMessage($topic, (string) json_encode($payload));

        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_invalid_registration_payload_is_rejected_by_handler_validation(): void
    {
        $handler = app(RegistrationHandler::class);
        $lockerBank = LockerBankFactory::new()->create();

        $topic = sprintf('locker/register/%s', $lockerBank->provisioning_token);
        $payload = [
            'message_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        ];

        $handler->handleMessage($topic, (string) json_encode($payload));

        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_missing_message_id_is_rejected_for_heartbeat_state(): void
    {
        $handler = app(HeartbeatHandler::class);
        $lockerBank = LockerBankFactory::new()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'connection_status' => 'offline',
            'last_heartbeat_at' => null,
        ]);

        $handler->handleMessage(
            "locker/{$lockerBank->id}/state",
            (string) json_encode([
                'event' => 'heartbeat',
                'data' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]),
        );

        $lockerBank->refresh();

        $this->assertNull($lockerBank->last_heartbeat_at);
        $this->assertSame('offline', $lockerBank->connection_status);
    }

    public function test_invalid_device_event_payload_is_rejected_by_handler_validation(): void
    {
        $handler = app(DeviceEventHandler::class);
        $topic = 'locker/11111111-1111-1111-1111-111111111111/event';

        $handler->handleMessage($topic, (string) json_encode([
            'message_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'timestamp' => now()->toIso8601String(),
            'data' => ['compartment_id' => 1],
        ]));

        $storedCount = EloquentStoredEvent::query()
            ->where('event_class', DeviceEventReceived::class)
            ->count();

        $this->assertSame(0, $storedCount);
    }
}
