<?php

namespace Tests\Feature;

use App\Models\MqttUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MosquittoAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mqtt-client.webhooks.pass', 'test-secret');
        config()->set('mqtt-client.system.provisioning_username', 'provisioning_client');
        config()->set('mqtt-client.system.provisioning_password', 'provisioning-pass');
        config()->set('mqtt-client.system.backend_username', 'laravel_backend');
        config()->set('mqtt-client.system.backend_password', 'backend-pass');
    }

    public function test_mosq_auth_requires_shared_secret(): void
    {
        $response = $this->postJson('/api/mosq/auth', [
            'username' => 'provisioning_client',
            'password' => 'provisioning-pass',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'allow' => false,
            ]);
    }

    public function test_mosq_auth_returns_500_when_secret_not_configured(): void
    {
        config()->set('mqtt-client.webhooks.pass', '');

        $response = $this->postJson('/api/mosq/auth?mosq_secret=anything', [
            'username' => 'provisioning_client',
            'password' => 'provisioning-pass',
        ]);

        $response->assertStatus(500)
            ->assertJson([
                'allow' => false,
            ]);
    }

    public function test_provisioning_user_can_authenticate_with_configured_password(): void
    {
        $response = $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'password' => 'provisioning-pass',
        ]);

        $response->assertOk()
            ->assertJson([
                'allow' => true,
                'ok' => true,
            ]);
    }

    public function test_backend_user_can_authenticate_with_configured_password(): void
    {
        $response = $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => 'laravel_backend',
            'password' => 'backend-pass',
        ]);

        $response->assertOk()
            ->assertJson([
                'allow' => true,
                'ok' => true,
            ]);
    }

    public function test_disabled_mqtt_user_cannot_authenticate(): void
    {
        MqttUser::factory()->create([
            'username' => 'device_1',
            'password_hash' => Hash::make('device-pass'),
            'enabled' => false,
        ]);

        $response = $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => 'device_1',
            'password' => 'device-pass',
        ]);

        $response->assertOk()
            ->assertJson([
                'allow' => false,
                'ok' => false,
            ]);
    }

    public function test_enabled_mqtt_user_can_authenticate_with_valid_password(): void
    {
        MqttUser::factory()->create([
            'username' => 'device_1',
            'password_hash' => Hash::make('device-pass'),
            'enabled' => true,
        ]);

        $response = $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => 'device_1',
            'password' => 'device-pass',
        ]);

        $response->assertOk()
            ->assertJson([
                'allow' => true,
                'ok' => true,
            ]);
    }

    public function test_mosq_acl_denies_by_default_for_unknown_user(): void
    {
        $response = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'unknown',
            'clientid' => 'c1',
            'topic' => 'locker/unknown/command',
            'acc' => 1,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'allow' => false,
                'ok' => false,
            ]);
    }

    public function test_backend_user_is_allowed_to_access_any_topic(): void
    {
        $response = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'laravel_backend',
            'clientid' => 'backend-client',
            'topic' => 'some/random/topic',
            'acc' => 2,
        ]);

        $response->assertOk()
            ->assertJson([
                'allow' => true,
                'ok' => true,
            ]);
    }

    public function test_provisioning_user_can_publish_register_request_only(): void
    {
        $allowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'clientid' => 'prov-1',
            'topic' => 'locker/register/device-123',
            'acc' => 2, // publish
        ]);

        $allowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $denied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'clientid' => 'prov-1',
            'topic' => 'locker/register/device-123/extra',
            'acc' => 2,
        ]);

        $denied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_provisioning_user_publish_denies_empty_single_level(): void
    {
        $response = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'clientid' => 'prov-1',
            'topic' => 'locker/register/',
            'acc' => 2,
        ]);

        $response->assertStatus(403)
            ->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_provisioning_user_can_subscribe_only_to_own_reply_topic_by_clientid(): void
    {
        $allowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'clientid' => 'prov-1',
            'topic' => 'locker/provisioning/reply/prov-1',
            'acc' => 1, // read/subscribe-like
        ]);

        $allowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $denied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'clientid' => 'prov-1',
            'topic' => 'locker/provisioning/reply/other',
            'acc' => 1,
        ]);

        $denied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_device_user_can_publish_only_state_response_and_event(): void
    {
        MqttUser::factory()->create([
            'username' => 'device_1',
            'enabled' => true,
        ]);

        $state = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/state',
            'acc' => 2,
        ]);
        $state->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $response = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/response',
            'acc' => 2,
        ]);
        $response->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $event = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/event',
            'acc' => 2,
        ]);
        $event->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $commandDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/command',
            'acc' => 2,
        ]);
        $commandDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_device_user_can_subscribe_only_to_command_topic(): void
    {
        MqttUser::factory()->create([
            'username' => 'device_1',
            'enabled' => true,
        ]);

        $allowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/command',
            'acc' => 1,
        ]);
        $allowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $stateDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/state',
            'acc' => 1,
        ]);
        $stateDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);

        $extraDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/command/extra',
            'acc' => 1,
        ]);
        $extraDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_device_readwrite_acc_is_treated_as_write_and_does_not_allow_command(): void
    {
        MqttUser::factory()->create([
            'username' => 'device_1',
            'enabled' => true,
        ]);

        $response = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/command',
            'acc' => 3, // readwrite -> hits write branch
        ]);

        $response->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }
}
