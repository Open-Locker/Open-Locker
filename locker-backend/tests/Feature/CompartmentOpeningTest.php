<?php

namespace Tests\Feature;

use App\Reactors\MqttReactor;
use App\Services\LockerService;
use App\StorableEvents\CompartmentOpeningRequested;
use Database\Factories\CompartmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpMqtt\Client\Facades\MQTT;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class CompartmentOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_locker_service_records_compartment_opening_requested_event(): void
    {
        $compartment = CompartmentFactory::new()->create([
            'number' => 1,
        ]);

        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        app(LockerService::class)->openCompartment($compartment);

        $this->assertCount(1, $mqttClient->published);

        $stored = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpeningRequested::class)
            ->first();

        $this->assertNotNull($stored);

        /** @var array<string, mixed> $props */
        $props = $stored->event_properties;

        $lockerBankUuid = $props['lockerBankUuid'];
        $compartmentUuid = $props['compartmentUuid'];
        $compartmentNumber = $props['compartmentNumber'];
        $commandId = $props['commandId'];

        $this->assertSame((string) $compartment->locker_bank_id, (string) $lockerBankUuid);
        $this->assertSame((string) $compartment->id, (string) $compartmentUuid);
        $this->assertSame(1, (int) $compartmentNumber);
        $this->assertIsString($commandId);
        $this->assertNotEmpty($commandId);
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

        $mqttClient = new FakeMqttClient;
        $topicExpected = "locker/{$lockerBankUuid}/command";

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        app(MqttReactor::class)->onCompartmentOpeningRequested($event);

        $this->assertCount(1, $mqttClient->published);
        $published = $mqttClient->published[0];

        $this->assertSame($topicExpected, $published['topic']);
        $this->assertSame(1, $published['qos']);

        $decoded = json_decode($published['payload'], true);
        $this->assertIsArray($decoded);

        $messageId = $decoded['message_id'];
        $timestamp = $decoded['timestamp'] ?? null;

        $this->assertSame('open_compartment', $decoded['action'] ?? null);
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        $this->assertSame($commandId, $decoded['transaction_id'] ?? null);
        $this->assertIsString($timestamp);
        $this->assertNotEmpty($timestamp);
        \Carbon\CarbonImmutable::parse($timestamp); // should not throw
        $this->assertSame($compartmentUuid, $decoded['data']['compartment_id'] ?? null);
        $this->assertSame($compartmentNumber, $decoded['data']['compartment_number'] ?? null);
    }
}
