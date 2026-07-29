<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LockerBankResource;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\Pages\ListLockerBanks;
use App\Models\AuditEvent;
use App\Models\LockerBank;
use App\Models\MqttUser;
use App\Models\User;
use App\Mqtt\Handlers\RegistrationHandler;
use App\Services\LockerProvisioningResetService;
use App\Services\MqttUserService;
use App\StorableEvents\LockerProvisioningReset;
use App\StorableEvents\LockerWasProvisioned;
use App\Support\Audit\AuditEventPresenter;
use Database\Factories\LockerBankFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpMqtt\Client\Facades\MQTT;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class LockerBankProvisioningResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration replies are published for real, and CI has no broker.
        // Locally the container resolves `mqtt` and the tests pass either way,
        // which is exactly why this has to be faked rather than relied upon.
        MQTT::shouldReceive('connection')->andReturn(new FakeMqttClient);

        config()->set('mqtt-client.webhooks.pass', 'test-secret');
        config()->set('mqtt-client.system.provisioning_username', 'provisioning_client');
        config()->set('mqtt-client.system.provisioning_password', 'provisioning-pass');
        config()->set('mqtt-client.system.backend_username', 'laravel_backend');
        config()->set('mqtt-client.system.backend_password', 'backend-pass');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    /**
     * The very first user created becomes admin automatically, so an
     * unprivileged user only exists once somebody else holds that seat.
     */
    private function unprivilegedUser(): User
    {
        $this->admin();

        return User::factory()->create();
    }

    private function service(): LockerProvisioningResetService
    {
        return app(LockerProvisioningResetService::class);
    }

    private function provisionedBank(): LockerBank
    {
        return LockerBankFactory::new()->create([
            'provisioned_at' => now(),
            'connection_status' => 'online',
            'connection_status_changed_at' => now(),
            'last_heartbeat_at' => now(),
            'last_config_sent_at' => now(),
            'last_config_sent_hash' => str_repeat('a', 64),
            'last_config_ack_at' => now(),
            'last_config_ack_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_reset_rotates_the_token_and_clears_provisioned_at(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;

        $newToken = $this->service()->reset($lockerBank, $this->admin());

        $lockerBank->refresh();
        $this->assertSame($newToken, $lockerBank->provisioning_token);
        $this->assertNotSame($oldToken, $lockerBank->provisioning_token);
        $this->assertNull($lockerBank->provisioned_at);
    }

    public function test_reset_requires_the_configure_permission(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->reset($lockerBank, $this->unprivilegedUser());
        } finally {
            // Authorization is enforced before anything is touched.
            $lockerBank->refresh();
            $this->assertSame($oldToken, $lockerBank->provisioning_token);
            $this->assertNotNull($lockerBank->provisioned_at);
            $this->assertSame(0, EloquentStoredEvent::query()
                ->where('event_class', LockerProvisioningReset::class)
                ->count());
        }
    }

    public function test_reset_without_an_actor_is_denied(): void
    {
        $lockerBank = $this->provisionedBank();

        $this->expectException(AuthorizationException::class);

        $this->service()->reset($lockerBank, null);
    }

    public function test_event_records_actor_and_timestamp_but_never_the_token(): void
    {
        $lockerBank = $this->provisionedBank();
        $admin = $this->admin();
        $oldToken = $lockerBank->provisioning_token;

        $newToken = $this->service()->reset($lockerBank, $admin);

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->latest('id')
            ->firstOrFail();

        $properties = $storedEvent->event_properties;

        $this->assertSame((string) $lockerBank->id, $properties['lockerBankUuid'] ?? null);
        $this->assertSame($admin->id, $properties['actorUserId'] ?? null);
        $this->assertNotEmpty($properties['resetAtIso8601'] ?? null);

        // Neither the retired nor the fresh credential may be reconstructable
        // from the event store.
        $this->assertArrayNotHasKey('newProvisioningToken', $properties);
        $serialised = (string) json_encode($storedEvent->getAttributes());
        $this->assertStringNotContainsString($newToken, $serialised);
        $this->assertStringNotContainsString($oldToken, $serialised);
    }

    public function test_reset_marks_the_bank_offline_and_clears_config_state(): void
    {
        $lockerBank = $this->provisionedBank();

        $this->service()->reset($lockerBank, $this->admin());

        $lockerBank->refresh();

        $this->assertSame('offline', $lockerBank->connection_status);
        $this->assertNotNull($lockerBank->connection_status_changed_at);
        $this->assertNull($lockerBank->last_heartbeat_at);
        $this->assertNull($lockerBank->last_config_sent_at);
        $this->assertNull($lockerBank->last_config_sent_hash);
        $this->assertNull($lockerBank->last_config_ack_at);
        $this->assertNull($lockerBank->last_config_ack_hash);

        // A replacement client has acknowledged nothing, so the bank must read
        // dirty until a fresh apply_config round trip.
        $this->assertTrue($lockerBank->isConfigDirty());
    }

    public function test_reset_deletes_the_mqtt_user(): void
    {
        $lockerBank = $this->provisionedBank();
        app(MqttUserService::class)->createUser((string) $lockerBank->id, 'device-pass', (string) $lockerBank->id);

        $this->assertDatabaseHas('mqtt_users', ['username' => (string) $lockerBank->id]);

        $this->service()->reset($lockerBank, $this->admin());

        $this->assertDatabaseMissing('mqtt_users', ['username' => (string) $lockerBank->id]);
    }

    public function test_deleted_mqtt_user_is_denied_by_auth_and_acl(): void
    {
        $lockerBank = $this->provisionedBank();
        $username = (string) $lockerBank->id;
        app(MqttUserService::class)->createUser($username, 'device-pass', $username);

        $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => $username,
            'password' => 'device-pass',
        ])->assertOk()->assertJson(['allow' => true]);

        $this->service()->reset($lockerBank, $this->admin());

        // Denial shapes differ between the two endpoints: auth answers 200 with
        // allow:false, ACL answers 403. Both are read by mosquitto-go-auth.
        $this->postJson('/api/mosq/auth?mosq_secret=test-secret', [
            'username' => $username,
            'password' => 'device-pass',
        ])->assertOk()->assertJson(['allow' => false]);

        $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => $username,
            'topic' => "locker/{$username}/state/heartbeat",
            'acc' => 2,
            'clientid' => $username,
        ])->assertStatus(403)->assertJson(['allow' => false]);
    }

    public function test_old_token_stops_working_and_the_new_one_re_provisions(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;

        $newToken = $this->service()->reset($lockerBank, $this->admin());

        $handler = app(RegistrationHandler::class);

        // The retired token no longer resolves to a bank.
        $handler->handleMessage(
            "locker/register/{$oldToken}",
            (string) json_encode([
                'message_id' => (string) Str::uuid(),
                'client_id' => 'prov-client-old',
                'timestamp' => now()->toIso8601String(),
            ]),
        );

        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->count());

        // The fresh one provisions the same bank again, recreating the legacy
        // UUID username with a newly generated password.
        $handler->handleMessage(
            "locker/register/{$newToken}",
            (string) json_encode([
                'message_id' => (string) Str::uuid(),
                'client_id' => 'prov-client-new',
                'timestamp' => now()->toIso8601String(),
            ]),
        );

        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->count());

        $lockerBank->refresh();
        $this->assertNotNull($lockerBank->provisioned_at);

        $mqttUser = MqttUser::where('username', (string) $lockerBank->id)->first();
        $this->assertNotNull($mqttUser);
        $this->assertNotSame('', (string) $mqttUser->password_hash);
    }

    public function test_repeated_resets_rotate_the_token_each_time(): void
    {
        $lockerBank = $this->provisionedBank();
        $admin = $this->admin();

        $first = $this->service()->reset($lockerBank, $admin);
        $second = $this->service()->reset($lockerBank->fresh(), $admin);

        $this->assertNotSame($first, $second);

        $lockerBank->refresh();
        $this->assertSame($second, $lockerBank->provisioning_token);
        $this->assertSame(2, EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->count());
    }

    public function test_a_failure_mid_reset_leaves_the_token_untouched(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;

        $this->mock(MqttUserService::class, function ($mock): void {
            $mock->shouldReceive('deleteUser')
                ->once()
                ->andThrow(new \RuntimeException('broker unavailable'));
        });

        try {
            $this->service()->reset($lockerBank, $this->admin());
            $this->fail('Expected the reset to propagate the failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('broker unavailable', $e->getMessage());
        }

        // The whole reset is one transaction, so a half-rotated bank cannot
        // survive: the operator still holds a token that works.
        $lockerBank->refresh();
        $this->assertSame($oldToken, $lockerBank->provisioning_token);
        $this->assertNotNull($lockerBank->provisioned_at);
        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->count());
    }

    public function test_admin_can_reset_from_the_locker_bank_table(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;
        app(MqttUserService::class)->createUser((string) $lockerBank->id, 'device-pass', (string) $lockerBank->id);

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->callAction(TestAction::make('resetProvisioning')->table($lockerBank))
            ->assertHasNoActionErrors();

        $lockerBank->refresh();
        $this->assertNotSame($oldToken, $lockerBank->provisioning_token);
        $this->assertNull($lockerBank->provisioned_at);
        $this->assertDatabaseMissing('mqtt_users', ['username' => (string) $lockerBank->id]);
    }

    public function test_admin_can_reset_from_the_bank_edit_page(): void
    {
        $lockerBank = $this->provisionedBank();
        $oldToken = $lockerBank->provisioning_token;
        app(MqttUserService::class)->createUser((string) $lockerBank->id, 'device-pass', (string) $lockerBank->id);

        Livewire::actingAs($this->admin())
            ->test(EditLockerBank::class, ['record' => $lockerBank->getKey()])
            ->callAction('resetProvisioning')
            ->assertHasNoActionErrors();

        $lockerBank->refresh();
        $this->assertNotSame($oldToken, $lockerBank->provisioning_token);
        $this->assertNull($lockerBank->provisioned_at);
        $this->assertSame('offline', $lockerBank->connection_status);
        $this->assertDatabaseMissing('mqtt_users', ['username' => (string) $lockerBank->id]);
    }

    public function test_the_whole_locker_bank_screen_is_closed_without_the_configure_permission(): void
    {
        $this->actingAs($this->unprivilegedUser());

        // The action's own visibility check never gets a chance to matter: the
        // resource guarding the screen needs the same permission. Denial at the
        // service is what actually protects the reset — see
        // test_reset_requires_the_configure_permission.
        $this->assertFalse(LockerBankResource::canAccess());

        $this->get(route('filament.admin.resources.locker-banks.index'))
            ->assertForbidden();
    }

    public function test_audit_log_shows_the_reset_with_its_actor_and_no_token(): void
    {
        $lockerBank = $this->provisionedBank();
        $admin = $this->admin();

        $newToken = $this->service()->reset($lockerBank, $admin);

        $presenter = app(AuditEventPresenter::class);

        $this->assertContains(
            LockerProvisioningReset::class,
            $presenter->auditableEventClasses(),
            'The reset must be visible in the admin audit log.',
        );

        $auditEvent = AuditEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->latest('id')
            ->firstOrFail();

        $description = $presenter->describe($auditEvent);

        $this->assertSame($admin->fullName(), $presenter->actorName($auditEvent));
        $this->assertStringNotContainsString($newToken, $description);
        $this->assertSame(__('Provisioning reset'), $presenter->label($auditEvent->event_class));
    }

    public function test_acl_checks_never_log_the_registration_token(): void
    {
        $lockerBank = $this->provisionedBank();
        $token = $lockerBank->provisioning_token;

        $logged = [];
        Log::listen(function (MessageLogged $entry) use (&$logged): void {
            $logged[] = $entry->message.' '.json_encode($entry->context);
        });

        // Mosquitto ACL-checks every registration publish, so this endpoint sees
        // the token on the topic more often than the handlers do.
        $this->postJson('/api/mosq/acl?mosq_secret=test-secret', [
            'username' => 'provisioning_client',
            'topic' => "locker/register/{$token}",
            'acc' => 2,
            'clientid' => 'prov-client-acl',
        ]);

        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    public function test_registration_logs_never_contain_the_token_or_its_topic(): void
    {
        $lockerBank = $this->provisionedBank();
        $token = $lockerBank->provisioning_token;

        $logged = [];
        Log::listen(function (MessageLogged $entry) use (&$logged): void {
            $logged[] = $entry->message.' '.json_encode($entry->context);
        });

        $handler = app(RegistrationHandler::class);
        $payload = (string) json_encode([
            'message_id' => (string) Str::uuid(),
            'client_id' => 'prov-client-logging',
            'timestamp' => now()->toIso8601String(),
        ]);

        // Both the accepted and the rejected path log, and both see the token
        // as the topic suffix.
        $handler->handleMessage("locker/register/{$token}", $payload);
        $handler->handleMessage(
            'locker/register/'.str_repeat('z', 64),
            (string) json_encode([
                'message_id' => (string) Str::uuid(),
                'client_id' => 'prov-client-unknown',
                'timestamp' => now()->toIso8601String(),
            ]),
        );

        $this->assertNotEmpty($logged, 'Expected registration handling to log something.');

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
            $this->assertStringNotContainsString(str_repeat('z', 64), $line);
        }
    }
}
