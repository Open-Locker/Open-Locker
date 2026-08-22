<?php

namespace Tests\Feature;

use App\Models\LockerBank;
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

    /**
     * `?mosq_secret[]=x` makes query() return an array. Casting that to a string
     * would compare the literal "Array" against the secret and emit a PHP
     * warning, so a non-string secret must be rejected outright.
     */
    public function test_mosq_auth_rejects_an_array_shaped_secret(): void
    {
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $response = $this->postJson('/api/mosq/auth?mosq_secret[]=test-secret', [
                'username' => 'provisioning_client',
                'password' => 'provisioning-pass',
            ]);
        } finally {
            restore_error_handler();
        }

        $response->assertStatus(401)
            ->assertJson(['allow' => false]);

        // Rejecting outright is what keeps this quiet; casting the array would
        // still deny the request, but only after warning on every attempt.
        $this->assertSame(
            [],
            array_values(array_filter($raised, static fn (string $m): bool => str_contains($m, 'Array to string conversion'))),
            'An array-shaped secret must not reach a string cast.'
        );
    }

    /**
     * The array is discarded rather than smuggled through, even when the real
     * secret is present as one of its elements.
     */
    public function test_an_array_shaped_secret_cannot_stand_in_for_the_real_one(): void
    {
        $response = $this->postJson('/api/mosq/auth?mosq_secret[0]=test-secret&mosq_secret[1]=x', [
            'username' => 'provisioning_client',
            'password' => 'provisioning-pass',
        ]);

        $response->assertStatus(401);
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

    public function test_device_user_can_publish_split_state_topics_response_and_event(): void
    {
        $user = MqttUser::factory()->create([
            'username' => 'device_1',
            'enabled' => true,
        ]);
        $lockerUuid = (string) $user->locker_bank_id;

        foreach ([
            "locker/{$lockerUuid}/state/heartbeat",
            "locker/{$lockerUuid}/state/compartments",
            "locker/{$lockerUuid}/state/connection",
            "locker/{$lockerUuid}/response",
            "locker/{$lockerUuid}/event",
        ] as $topic) {
            $r = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
                'username' => 'device_1',
                'clientid' => 'device_1_client',
                'topic' => $topic,
                'acc' => 2,
            ]);
            $r->assertOk()->assertJson(['allow' => true, 'ok' => true]);
        }

        $commandDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => "locker/{$lockerUuid}/command",
            'acc' => 2,
        ]);
        $commandDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);

        // The username is not a topic namespace: publishing under it must fail
        // even though it authenticates.
        $usernameTopicDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => 'locker/device_1/event',
            'acc' => 2,
        ]);
        $usernameTopicDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }

    public function test_device_user_can_subscribe_only_to_command_topic(): void
    {
        $user = MqttUser::factory()->create([
            'username' => 'device_1',
            'enabled' => true,
        ]);
        $lockerUuid = (string) $user->locker_bank_id;

        $allowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => "locker/{$lockerUuid}/command",
            'acc' => 1,
        ]);
        $allowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);

        $stateDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => "locker/{$lockerUuid}/state/heartbeat",
            'acc' => 1,
        ]);
        $stateDenied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);

        $extraDenied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_1',
            'clientid' => 'device_1_client',
            'topic' => "locker/{$lockerUuid}/command/extra",
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

    public function test_legacy_uuid_username_is_authorised_through_the_same_mapping(): void
    {
        // Identities issued before opaque usernames have username == locker uuid.
        // They must keep working with no special case: the mapping authorises them.
        $bank = LockerBank::factory()->create();
        MqttUser::factory()->create([
            'locker_bank_id' => $bank->id,
            'username' => (string) $bank->id,
            'enabled' => true,
        ]);

        $allowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => (string) $bank->id,
            'clientid' => 'legacy_client',
            'topic' => "locker/{$bank->id}/event",
            'acc' => 2,
        ]);

        $allowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);
    }

    public function test_device_user_cannot_reach_another_lockers_topics(): void
    {
        $own = MqttUser::factory()->create(['username' => 'device_own', 'enabled' => true]);
        $other = LockerBank::factory()->create();

        $denied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'device_own',
            'clientid' => 'device_own_client',
            'topic' => "locker/{$other->id}/command",
            'acc' => 1,
        ]);

        $denied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
        $this->assertNotSame((string) $own->locker_bank_id, (string) $other->id);
    }

    public function test_wildcard_usernames_cannot_widen_device_access(): void
    {
        // A username of '#' or '+' must be compared literally, never expanded,
        // or an identity could name its way into every locker's topics.
        foreach (['#', '+'] as $wildcard) {
            $user = MqttUser::factory()->create(['username' => $wildcard, 'enabled' => true]);
            $other = LockerBank::factory()->create();

            $denied = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
                'username' => $wildcard,
                'clientid' => 'wildcard_client',
                'topic' => "locker/{$other->id}/event",
                'acc' => 2,
            ]);
            $denied->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);

            $ownAllowed = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
                'username' => $wildcard,
                'clientid' => 'wildcard_client',
                'topic' => "locker/{$user->locker_bank_id}/event",
                'acc' => 2,
            ]);
            $ownAllowed->assertOk()->assertJson(['allow' => true, 'ok' => true]);
        }
    }

    public function test_disabled_identity_is_denied_for_auth_and_acl(): void
    {
        $user = MqttUser::factory()->create([
            'username' => 'revoked_device',
            'password_hash' => Hash::make('password123'),
            'enabled' => false,
            'revoked_at' => now(),
        ]);

        $auth = $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => 'revoked_device',
            'password' => 'password123',
        ]);
        $auth->assertJson(['allow' => false, 'ok' => false]);

        $acl = $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'revoked_device',
            'clientid' => 'revoked_client',
            'topic' => "locker/{$user->locker_bank_id}/event",
            'acc' => 2,
        ]);
        $acl->assertStatus(403)->assertJson(['allow' => false, 'ok' => false]);
    }
}
