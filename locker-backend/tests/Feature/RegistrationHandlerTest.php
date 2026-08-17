<?php

namespace Tests\Feature;

use App\Models\LockerBank;
use App\Models\User;
use App\Mqtt\Handlers\RegistrationHandler;
use App\Services\LockerProvisioningService;
use App\StorableEvents\LockerWasProvisioned;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PhpMqtt\Client\Facades\MQTT;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class RegistrationHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('provisioning.token_hmac_key', str_repeat('k', 64));
    }

    /**
     * @return array{LockerBank, string}
     */
    private function bankWithIssuedToken(): array
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $lockerBank = LockerBankFactory::new()->create();
        $token = app(LockerProvisioningService::class)->restart($lockerBank, $admin);

        return [$lockerBank, $token];
    }

    public function test_valid_registration_message_records_provisioning_event(): void
    {
        MQTT::shouldReceive('connection')->andReturn(new FakeMqttClient);

        $handler = app(RegistrationHandler::class);
        [$lockerBank, $token] = $this->bankWithIssuedToken();

        $topic = sprintf('locker/register/%s', $token);
        $handler->handleMessage($topic, (string) json_encode([
            'message_id' => '11111111-1111-1111-1111-111111111111',
            'client_id' => 'prov-client-1',
            'timestamp' => now()->toIso8601String(),
        ]));

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame((string) $lockerBank->id, $storedEvent->event_properties['lockerBankUuid'] ?? null);
        $this->assertSame('locker/provisioning/reply/prov-client-1', $storedEvent->event_properties['replyToTopic'] ?? null);
        $this->assertSame(
            $lockerBank->fresh()->provisioning_generation,
            $storedEvent->event_properties['provisioningGeneration'] ?? null,
        );

        $lockerBank->refresh();
        $this->assertNotNull($lockerBank->provisioned_at);
        $this->assertNull($lockerBank->provisioning_token_hmac);
    }

    public function test_registration_message_without_timestamp_is_rejected(): void
    {
        $handler = app(RegistrationHandler::class);
        [$lockerBank, $token] = $this->bankWithIssuedToken();

        $topic = sprintf('locker/register/%s', $token);
        $handler->handleMessage($topic, (string) json_encode([
            'message_id' => '22222222-2222-2222-2222-222222222222',
            'client_id' => 'prov-client-1',
        ]));

        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->count());

        $lockerBank->refresh();
        $this->assertNull($lockerBank->provisioned_at);
    }

    public function test_consumed_provisioning_token_is_rejected(): void
    {
        MQTT::shouldReceive('connection')
            ->twice()
            ->with('publisher')
            ->andReturn(new FakeMqttClient);

        [$lockerBank, $token] = $this->bankWithIssuedToken();
        $handler = app(RegistrationHandler::class);
        $payload = (string) json_encode([
            'message_id' => '55555555-5555-5555-5555-555555555555',
            'client_id' => 'prov-client-consumed',
            'timestamp' => now()->toIso8601String(),
        ]);

        $handler->handleMessage("locker/register/{$token}", $payload);
        $handler->handleMessage("locker/register/{$token}", (string) json_encode([
            'message_id' => '66666666-6666-6666-6666-666666666666',
            'client_id' => 'prov-client-consumed',
            'timestamp' => now()->toIso8601String(),
        ]));

        $this->assertNull($lockerBank->fresh()->provisioning_token_hmac);
        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->count());
    }

    public function test_registration_message_with_unsafe_client_id_is_rejected_before_reply_topic_is_derived(): void
    {
        MQTT::shouldReceive('connection')->never();

        $handler = app(RegistrationHandler::class);
        $unsafeClientIds = ['client/evil', '+', '#', '   '];

        foreach ($unsafeClientIds as $index => $clientId) {
            $handler->handleMessage('locker/register/unknown-token', (string) json_encode([
                'message_id' => sprintf('44444444-4444-4444-4444-%012d', $index),
                'client_id' => $clientId,
                'timestamp' => now()->toIso8601String(),
            ]));
        }

        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_unknown_provisioning_token_publishes_contract_error_reply(): void
    {
        $mqttClient = new FakeMqttClient;

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn($mqttClient);

        app(RegistrationHandler::class)->handleMessage('locker/register/unknown-token', (string) json_encode([
            'message_id' => '33333333-3333-3333-3333-333333333333',
            'client_id' => 'prov-client-1',
            'timestamp' => now()->toIso8601String(),
        ]));

        $this->assertSame(0, EloquentStoredEvent::query()->count());
        $this->assertCount(1, $mqttClient->published);
        $this->assertSame('locker/provisioning/reply/prov-client-1', $mqttClient->published[0]['topic']);

        $payload = json_decode($mqttClient->published[0]['payload'], true);

        $this->assertIsArray($payload);
        $this->assertIsString($payload['message_id'] ?? null);
        $this->assertIsString($payload['timestamp'] ?? null);
        $this->assertSame('error', $payload['status'] ?? null);
        $this->assertSame('Invalid or expired provisioning token.', $payload['message'] ?? null);
    }
}
