<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\Pages\ListLockerBanks;
use App\Models\LockerBank;
use App\Models\MqttUser;
use App\Models\User;
use App\Projectors\LockerBankProjector;
use App\Services\LockerProvisioningService;
use App\Services\MqttUserService;
use App\StorableEvents\LockerProvisioningReset;
use App\StorableEvents\LockerProvisioningTokenIssued;
use App\StorableEvents\LockerWasProvisioned;
use App\Support\Audit\AuditEventPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PhpMqtt\Client\Facades\MQTT;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class LockerBankProvisioningResetTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_KEY = 'test-provisioning-hmac-key-with-more-than-32-characters';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('provisioning.token_hmac_key', self::HMAC_KEY);
        MQTT::shouldReceive('connection')->andReturn(new FakeMqttClient);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function unprivilegedUser(): User
    {
        $this->admin();

        return User::factory()->create();
    }

    private function provisionedBank(): LockerBank
    {
        return LockerBank::factory()->create([
            'provisioned_at' => now(),
            'provisioning_generation' => '11111111-1111-1111-1111-111111111111',
            'connection_status' => 'online',
            'connection_status_changed_at' => now(),
            'last_heartbeat_at' => now(),
            'last_config_sent_at' => now(),
            'last_config_sent_hash' => str_repeat('a', 64),
            'last_config_ack_at' => now(),
            'last_config_ack_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_restart_stores_only_hmac_and_replayable_generation(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $admin = $this->admin();

        $token = app(LockerProvisioningService::class)->restart($lockerBank, $admin);
        $lockerBank->refresh();

        $expectedHmac = hash_hmac('sha256', $token, self::HMAC_KEY);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $lockerBank->provisioning_token_hmac);
        $this->assertSame($expectedHmac, $lockerBank->provisioning_token_hmac);
        $this->assertNotNull($lockerBank->provisioning_generation);
        $this->assertDatabaseMissing('locker_banks', ['provisioning_token_hmac' => $token]);

        $issuedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningTokenIssued::class)
            ->sole();
        $this->assertSame($expectedHmac, $issuedEvent->event_properties['provisioningTokenHmac'] ?? null);
        $this->assertSame($lockerBank->provisioning_generation, $issuedEvent->event_properties['provisioningGeneration'] ?? null);
        $this->assertSame($admin->id, $issuedEvent->event_properties['actorUserId'] ?? null);
        $this->assertSame(
            [LockerProvisioningReset::class, LockerProvisioningTokenIssued::class],
            EloquentStoredEvent::query()
                ->whereIn('event_class', [
                    LockerProvisioningReset::class,
                    LockerProvisioningTokenIssued::class,
                ])
                ->orderBy('id')
                ->pluck('event_class')
                ->all(),
        );
        $presenter = app(AuditEventPresenter::class);
        $this->assertContains(
            LockerProvisioningTokenIssued::class,
            $presenter->auditableEventClasses(),
        );
        $this->assertSame(
            __('Provisioning token issued'),
            $presenter->label(LockerProvisioningTokenIssued::class),
        );

        $databaseState = json_encode([
            LockerBank::query()->whereKey($lockerBank->id)->firstOrFail()->getAttributes(),
            EloquentStoredEvent::query()->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $databaseState);
    }

    public function test_restart_requires_actor_permission_and_valid_hmac_key(): void
    {
        $lockerBank = LockerBank::factory()->create();

        try {
            app(LockerProvisioningService::class)->restart($lockerBank, $this->unprivilegedUser());
            $this->fail('Expected authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertNull($lockerBank->fresh()->provisioning_token_hmac);
        }

        config()->set('provisioning.token_hmac_key', null);

        $this->expectException(\RuntimeException::class);
        app(LockerProvisioningService::class)->restart($lockerBank, $this->admin());
    }

    public function test_example_placeholder_is_not_accepted_as_hmac_key(): void
    {
        config()->set(
            'provisioning.token_hmac_key',
            'change-me-to-a-dedicated-random-secret-of-at-least-32-characters',
        );

        $this->expectException(\RuntimeException::class);
        app(LockerProvisioningService::class)->hashToken('token');
    }

    public function test_rotation_invalidates_old_token_and_consumes_new_token(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $admin = $this->admin();
        $service = app(LockerProvisioningService::class);

        $oldToken = $service->restart($lockerBank, $admin);
        $newToken = $service->restart($lockerBank->fresh(), $admin);

        $this->assertNotSame($oldToken, $newToken);
        $this->assertFalse($service->acceptRegistration(
            $oldToken,
            'locker/provisioning/reply/old-client',
        ));
        $this->assertTrue($service->acceptRegistration(
            $newToken,
            'locker/provisioning/reply/new-client',
        ));
        $this->assertFalse($service->acceptRegistration(
            $newToken,
            'locker/provisioning/reply/retry-client',
        ));

        $lockerBank->refresh();
        $this->assertNotNull($lockerBank->provisioned_at);
        $this->assertNull($lockerBank->provisioning_token_hmac);
        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('event_class', LockerWasProvisioned::class)
            ->count());
    }

    public function test_provisioning_projection_uses_the_stored_event_time_during_replay(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $provisionedAt = CarbonImmutable::parse('2026-08-17T10:15:30+00:00');
        $event = (new LockerWasProvisioned(
            lockerBankUuid: (string) $lockerBank->id,
            replyToTopic: 'locker/provisioning/reply/replay-test',
        ))->setCreatedAt($provisionedAt);

        app(LockerBankProjector::class)->onLockerWasProvisioned($event);

        $lockerBank->refresh();
        $this->assertTrue($lockerBank->provisioned_at?->equalTo($provisionedAt) ?? false);
        $this->assertNull($lockerBank->provisioning_generation);
    }

    public function test_restart_resets_state_and_deletes_operational_mqtt_user(): void
    {
        $lockerBank = $this->provisionedBank();
        app(MqttUserService::class)->createUser(
            (string) $lockerBank->id,
            'device-password',
            (string) $lockerBank->id,
        );

        app(LockerProvisioningService::class)->restart($lockerBank, $this->admin());
        $lockerBank->refresh();

        $this->assertNull($lockerBank->provisioned_at);
        $this->assertSame('offline', $lockerBank->connection_status);
        $this->assertNull($lockerBank->last_heartbeat_at);
        $this->assertNull($lockerBank->last_config_sent_at);
        $this->assertNull($lockerBank->last_config_sent_hash);
        $this->assertNull($lockerBank->last_config_ack_at);
        $this->assertNull($lockerBank->last_config_ack_hash);
        $this->assertDatabaseMissing('mqtt_users', ['username' => (string) $lockerBank->id]);
        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->count());
    }

    public function test_restart_rolls_back_events_projection_and_mqtt_user_deletion_failure(): void
    {
        $lockerBank = $this->provisionedBank();
        MqttUser::factory()->create([
            'username' => (string) $lockerBank->id,
            'locker_bank_id' => $lockerBank->id,
        ]);
        $admin = $this->admin();
        $original = $lockerBank->getAttributes();
        $storedEventCount = EloquentStoredEvent::query()->count();

        $this->mock(MqttUserService::class, function ($mock): void {
            $mock->shouldReceive('deleteUser')
                ->once()
                ->andReturnUsing(function (string $username): never {
                    MqttUser::query()->where('username', $username)->delete();

                    throw new \RuntimeException('delete failed');
                });
        });

        try {
            app(LockerProvisioningService::class)->restart($lockerBank, $admin);
            $this->fail('Expected restart failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('delete failed', $exception->getMessage());
        }

        $lockerBank->refresh();
        $this->assertSame($original['provisioned_at'], $lockerBank->getRawOriginal('provisioned_at'));
        $this->assertSame($original['provisioning_generation'], $lockerBank->provisioning_generation);
        $this->assertNull($lockerBank->provisioning_token_hmac);
        $this->assertDatabaseHas('mqtt_users', ['username' => (string) $lockerBank->id]);
        $this->assertSame($storedEventCount, EloquentStoredEvent::query()->count());
    }

    public function test_logs_never_contain_plaintext_token(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $logged = [];
        Log::listen(function (MessageLogged $entry) use (&$logged): void {
            $logged[] = $entry->message.' '.json_encode($entry->context);
        });

        $token = app(LockerProvisioningService::class)->restart($lockerBank, $this->admin());
        app(LockerProvisioningService::class)->acceptRegistration(
            $token,
            'locker/provisioning/reply/log-test',
        );

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    public function test_filament_shows_returned_token_only_in_one_time_modal(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $token = str_repeat('T', 64);
        $admin = $this->admin();

        $this->mock(LockerProvisioningService::class, function ($mock) use ($admin, $lockerBank, $token): void {
            $mock->shouldReceive('restart')
                ->once()
                ->withArgs(fn (LockerBank $record, User $actor): bool => $record->is($lockerBank) && $actor->is($admin))
                ->andReturn($token);
        });

        $component = Livewire::actingAs($admin)
            ->test(EditLockerBank::class, ['record' => $lockerBank->getKey()])
            ->callAction('restartProvisioning');

        $component
            ->assertSet('mountedActions.0.name', 'showProvisioningToken')
            ->assertSet(
                'mountedActions.0.arguments',
                fn (array $arguments): bool => ! array_key_exists('token', $arguments),
            );

        $modalContent = $component->instance()->getMountedAction()?->getModalContent();
        $this->assertNotNull($modalContent);
        $this->assertStringContainsString($token, $modalContent->render());
        $this->assertStringNotContainsString(
            $token,
            json_encode($component->instance()->mountedActions, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString($token, json_encode(session()->all(), JSON_THROW_ON_ERROR));

        $component
            ->unmountAction()
            ->assertActionNotMounted()
            ->assertDontSee($token);
    }

    public function test_filament_table_action_preserves_context_for_the_one_time_modal(): void
    {
        $lockerBank = LockerBank::factory()->create();
        $token = str_repeat('T', 64);
        $admin = $this->admin();

        $this->mock(LockerProvisioningService::class, function ($mock) use ($token): void {
            $mock->shouldReceive('restart')->once()->andReturn($token);
        });

        $component = Livewire::actingAs($admin)
            ->test(ListLockerBanks::class)
            ->callTableAction('restartProvisioning', $lockerBank);

        $component
            ->assertSet('mountedActions.0.name', 'showProvisioningToken')
            ->assertSet('mountedActions.0.context.table', true)
            ->assertSet(
                'mountedActions.0.arguments',
                fn (array $arguments): bool => ! array_key_exists('token', $arguments),
            );

        $modalContent = $component->instance()->getMountedAction()?->getModalContent();
        $this->assertNotNull($modalContent);
        $this->assertStringContainsString($token, $modalContent->render());
    }
}
