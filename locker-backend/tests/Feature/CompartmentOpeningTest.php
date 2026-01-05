<?php

namespace Tests\Feature;

use App\Reactors\MqttReactor;
use App\Services\LockerService;
use App\StorableEvents\CompartmentOpeningRequested;
use Database\Factories\CompartmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpMqtt\Client\Facades\MQTT;
use PhpMqtt\Client\MqttClient;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_locker_service_records_compartment_opening_requested_event(): void
    {
        $compartment = CompartmentFactory::new()->create([
            'number' => 1,
        ]);

        app(LockerService::class)->openCompartment($compartment);

        $stored = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpeningRequested::class)
            ->first();

        $this->assertNotNull($stored);

        /** @var array<string, mixed> $props */
        $props = $stored->event_properties;

        $this->assertSame((string) $compartment->locker_bank_id, (string) ($props['lockerBankUuid'] ?? null));
        $this->assertSame((string) $compartment->id, (string) ($props['compartmentUuid'] ?? null));
        $this->assertSame(1, (int) ($props['compartmentNumber'] ?? 0));
        $this->assertIsString($props['commandId'] ?? null);
        $this->assertNotEmpty($props['commandId'] ?? null);
    }

    public function test_mqtt_reactor_publishes_open_command_to_expected_topic(): void
    {
        $lockerBankUuid = '11111111-1111-1111-1111-111111111111';
        $compartmentUuid = '22222222-2222-2222-2222-222222222222';
        $compartmentNumber = 3;
        $commandId = '33333333-3333-3333-3333-333333333333';

        $event = new CompartmentOpeningRequested(
            lockerBankUuid: $lockerBankUuid,
            compartmentUuid: $compartmentUuid,
            compartmentNumber: $compartmentNumber,
            commandId: $commandId,
        );

        $mqttClient = \Mockery::mock(MqttClient::class);
        $topicExpected = "locker/{$lockerBankUuid}/command";

        $mqttClient->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $topic, string $payload, int $qos) use ($topicExpected, $commandId, $compartmentUuid, $compartmentNumber) {
                $this->assertSame($topicExpected, $topic);
                $this->assertSame(1, $qos);

                $decoded = json_decode($payload, true);
                $this->assertIsArray($decoded);

                $this->assertSame('open_compartment', $decoded['action'] ?? null);
                $this->assertSame($commandId, $decoded['transaction_id'] ?? null);

                $timestamp = $decoded['timestamp'] ?? null;
                $this->assertIsString($timestamp);
                $this->assertNotEmpty($timestamp);
                \Carbon\CarbonImmutable::parse($timestamp); // should not throw

                $this->assertSame($compartmentUuid, $decoded['data']['compartment_id'] ?? null);
                $this->assertSame($compartmentNumber, $decoded['data']['compartment_number'] ?? null);

                return true;
            });

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        app(MqttReactor::class)->onCompartmentOpeningRequested($event);
    }
}
