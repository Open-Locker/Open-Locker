<?php

namespace Tests\Feature;

use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpMqtt\Client\Facades\MQTT;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class OutboundMqttPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_compartment_publisher_builds_expected_payload(): void
    {
        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        $event = new CompartmentOpeningRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            compartmentUuid: '22222222-2222-2222-2222-222222222222',
            compartmentNumber: 7,
            commandId: '33333333-3333-3333-3333-333333333333',
        );

        app(OpenCompartmentCommandPublisher::class)->publish($event);

        $this->assertCount(1, $mqttClient->published);
        $this->assertSame(
            'locker/11111111-1111-1111-1111-111111111111/command',
            $mqttClient->published[0]['topic'],
        );

        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertSame('open_compartment', $payload['action'] ?? null);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $payload['transaction_id'] ?? null);
        $this->assertArrayNotHasKey('compartment_id', $payload['data']);
        $this->assertSame(7, $payload['data']['compartment_number'] ?? null);
    }

    public function test_apply_config_publisher_builds_expected_payload(): void
    {
        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        $event = new LockerConfigApplyRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            commandId: '22222222-2222-2222-2222-222222222222',
            configHash: 'abc123',
            heartbeatIntervalSeconds: 15,
            compartments: [
                ['compartment_number' => 1, 'slave_id' => 1, 'address' => 0],
            ],
        );

        app(ApplyConfigCommandPublisher::class)->publish($event);

        $this->assertCount(1, $mqttClient->published);
        $this->assertSame(
            'locker/11111111-1111-1111-1111-111111111111/command',
            $mqttClient->published[0]['topic'],
        );

        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertSame('apply_config', $payload['action'] ?? null);
        $this->assertSame('abc123', $payload['data']['config_hash'] ?? null);
        $this->assertSame(15, $payload['data']['heartbeat_interval_seconds'] ?? null);
        $this->assertCount(1, $payload['data']['compartments'] ?? []);
    }

    public function test_provisioning_reply_publisher_builds_success_payload(): void
    {
        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        $event = new LockerWasProvisioned(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            replyToTopic: 'locker/provisioning/reply/test-client',
        );

        app(ProvisioningReplyPublisher::class)->publishSuccess(
            $event,
            'mqtt-user',
            'mqtt-password',
        );

        $this->assertCount(1, $mqttClient->published);
        $this->assertSame('locker/provisioning/reply/test-client', $mqttClient->published[0]['topic']);

        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertSame('mqtt-user', $payload['data']['mqtt_user'] ?? null);
        $this->assertSame('mqtt-password', $payload['data']['mqtt_password'] ?? null);
    }

    public function test_provisioning_reply_publisher_builds_failure_payload(): void
    {
        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        $event = new LockerProvisioningFailed(
            replyToTopic: 'locker/provisioning/reply/test-client',
            reason: 'Provisioning rejected',
        );

        app(ProvisioningReplyPublisher::class)->publishFailure($event);

        $this->assertCount(1, $mqttClient->published);
        $this->assertSame('locker/provisioning/reply/test-client', $mqttClient->published[0]['topic']);

        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertSame('error', $payload['status'] ?? null);
        $this->assertSame('Provisioning rejected', $payload['message'] ?? null);
    }
}
