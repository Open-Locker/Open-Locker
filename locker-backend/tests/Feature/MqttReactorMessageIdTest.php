<?php

namespace Tests\Feature;

use App\Reactors\MqttReactor;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpMqtt\Client\Facades\MQTT;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class MqttReactorMessageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_config_command_contains_message_id(): void
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

        app(MqttReactor::class)->onLockerConfigApplyRequested($event);

        $this->assertCount(1, $mqttClient->published);
        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertNotEmpty($payload['message_id'] ?? null);
        $this->assertSame('apply_config', $payload['action'] ?? null);
    }

    public function test_provisioning_success_reply_contains_message_id(): void
    {
        $mqttClient = new FakeMqttClient;

        LockerBankFactory::new()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
        ]);

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        $event = new LockerWasProvisioned(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            replyToTopic: 'locker/provisioning/reply/test-client',
        );

        app(MqttReactor::class)->onLockerWasProvisioned($event);

        $this->assertCount(1, $mqttClient->published);
        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertNotEmpty($payload['message_id'] ?? null);
        $this->assertSame('success', $payload['status'] ?? null);
    }

    public function test_provisioning_failure_reply_contains_message_id(): void
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

        app(MqttReactor::class)->onLockerProvisioningFailed($event);

        $this->assertCount(1, $mqttClient->published);
        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertNotEmpty($payload['message_id'] ?? null);
        $this->assertSame('error', $payload['status'] ?? null);
    }
}
