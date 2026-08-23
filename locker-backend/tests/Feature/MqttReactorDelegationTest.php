<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LockerBank;
use App\Models\MqttUser;
use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\Reactors\MqttReactor;
use App\Services\MqttUserService;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MqttReactorDelegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_compartment_event_is_delegated_to_publisher(): void
    {
        $event = new CompartmentOpeningRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            compartmentUuid: '22222222-2222-2222-2222-222222222222',
            compartmentNumber: 7,
            commandId: '33333333-3333-3333-3333-333333333333',
        );

        $this->mock(OpenCompartmentCommandPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onCompartmentOpeningRequested($event);
    }

    public function test_apply_config_event_is_delegated_to_publisher(): void
    {
        $event = new LockerConfigApplyRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            commandId: '22222222-2222-2222-2222-222222222222',
            configHash: 'abc123',
            heartbeatIntervalSeconds: 15,
            adapterType: 'waveshare_modbus',
            channelCount: 8,
            feedbackType: 'door_closing',
            compartments: [
                ['compartment_number' => 1, 'slaveId' => 1, 'address' => 0],
            ],
        );

        $this->mock(ApplyConfigCommandPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onLockerConfigApplyRequested($event);
    }

    public function test_provisioning_success_creates_user_and_delegates_reply_publish(): void
    {
        $generation = (string) Str::uuid();
        LockerBank::factory()->create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'provisioned_at' => now(),
            'provisioning_generation' => $generation,
        ]);

        $event = new LockerWasProvisioned(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            replyToTopic: 'locker/provisioning/reply/test-client',
            provisioningGeneration: $generation,
        );
        $generatedPassword = null;

        $generatedUsername = null;

        $this->mock(MqttUserService::class, function ($mock) use ($event, &$generatedPassword, &$generatedUsername): void {
            $mock->shouldReceive('revokeForLockerBank')
                ->once()
                ->with($event->lockerBankUuid)
                ->andReturn(0);

            $mock->shouldReceive('createUser')
                ->once()
                ->withArgs(function (string $username, string $password, string $lockerBankUuid) use ($event, &$generatedPassword, &$generatedUsername): bool {
                    $generatedPassword = $password;
                    $generatedUsername = $username;

                    // The username must no longer be the locker uuid: that is the
                    // coupling that let a revoked identity be recreated.
                    $this->assertNotSame($event->lockerBankUuid, $username);
                    $this->assertNotSame('', $username);
                    $this->assertSame($event->lockerBankUuid, $lockerBankUuid);
                    $this->assertNotSame('', $password);

                    return true;
                });
        });

        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use ($event, &$generatedPassword, &$generatedUsername): void {
            $mock->shouldReceive('publishSuccess')
                ->once()
                ->withArgs(function (LockerWasProvisioned $publishedEvent, string $mqttUser, string $mqttPassword) use ($event, &$generatedPassword, &$generatedUsername): bool {
                    $this->assertSame($event, $publishedEvent);
                    // The client is told the identity that was issued, not the
                    // locker uuid — that travels as its own field in the reply.
                    $this->assertSame($generatedUsername, $mqttUser);
                    $this->assertSame($generatedPassword, $mqttPassword);

                    return true;
                });
        });

        app(MqttReactor::class)->onLockerWasProvisioned($event);
    }

    public function test_stale_provisioning_jobs_after_reset_or_new_generation_are_ignored(): void
    {
        $lockerBank = LockerBank::factory()->create([
            'provisioned_at' => null,
            'provisioning_generation' => null,
        ]);
        $event = new LockerWasProvisioned(
            lockerBankUuid: (string) $lockerBank->id,
            replyToTopic: 'locker/provisioning/reply/test-client',
            provisioningGeneration: (string) Str::uuid(),
        );

        $this->mock(MqttUserService::class, function ($mock): void {
            $mock->shouldReceive('createUser')->never();
        });
        $this->mock(ProvisioningReplyPublisher::class, function ($mock): void {
            $mock->shouldReceive('publishSuccess')->never();
        });

        app(MqttReactor::class)->onLockerWasProvisioned($event);

        $lockerBank->forceFill([
            'provisioned_at' => now(),
            'provisioning_generation' => (string) Str::uuid(),
        ])->save();

        app(MqttReactor::class)->onLockerWasProvisioned($event);
    }

    public function test_generation_is_rechecked_after_user_creation_before_credentials_are_published(): void
    {
        $generation = (string) Str::uuid();
        $lockerBank = LockerBank::factory()->create([
            'provisioned_at' => now(),
            'provisioning_generation' => $generation,
        ]);
        $event = new LockerWasProvisioned(
            lockerBankUuid: (string) $lockerBank->id,
            replyToTopic: 'locker/provisioning/reply/test-client',
            provisioningGeneration: $generation,
        );

        $this->mock(MqttUserService::class, function ($mock) use ($lockerBank): void {
            $mock->shouldReceive('revokeForLockerBank')->once()->andReturn(0);
            $mock->shouldReceive('createUser')
                ->once()
                ->andReturnUsing(function () use ($lockerBank): void {
                    $lockerBank->newQuery()
                        ->whereKey($lockerBank->id)
                        ->update(['provisioning_generation' => (string) Str::uuid()]);
                });
        });
        $this->mock(ProvisioningReplyPublisher::class, function ($mock): void {
            $mock->shouldReceive('publishSuccess')->never();
        });

        app(MqttReactor::class)->onLockerWasProvisioned($event);
    }

    public function test_provisioning_failure_is_delegated_to_reply_publisher(): void
    {
        $event = new LockerProvisioningFailed(
            replyToTopic: 'locker/provisioning/reply/test-client',
            reason: 'Provisioning rejected',
        );

        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publishFailure')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onLockerProvisioningFailed($event);
    }

    public function test_a_retried_provisioning_event_leaves_exactly_one_live_identity(): void
    {
        // This reactor is queued and rethrows to trigger a retry, so the same
        // generation can be handled more than once. Issuing an opaque username per
        // attempt would accumulate live identities unless each issuance revokes
        // what came before it.
        $generation = (string) Str::uuid();
        $lockerBank = LockerBank::factory()->create([
            'provisioned_at' => now(),
            'provisioning_generation' => $generation,
        ]);

        $event = new LockerWasProvisioned(
            lockerBankUuid: (string) $lockerBank->id,
            replyToTopic: 'locker/provisioning/reply/test-client',
            provisioningGeneration: $generation,
        );

        $publishedUsernames = [];
        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use (&$publishedUsernames): void {
            $mock->shouldReceive('publishSuccess')
                ->twice()
                ->andReturnUsing(function (LockerWasProvisioned $e, string $mqttUser) use (&$publishedUsernames): void {
                    $publishedUsernames[] = $mqttUser;
                });
        });

        $reactor = app(MqttReactor::class);
        $reactor->onLockerWasProvisioned($event);
        $reactor->onLockerWasProvisioned($event);

        $identities = MqttUser::query()->where('locker_bank_id', $lockerBank->id)->get();

        $this->assertCount(2, $identities, 'each attempt issues its own identity');
        $this->assertCount(1, $identities->where('enabled', true), 'only one may remain live');
        $this->assertSame(
            $publishedUsernames[1],
            (string) $identities->firstWhere('enabled', true)?->username,
            'the live identity is the one whose credentials were published last',
        );

        foreach ($identities->where('enabled', false) as $revoked) {
            $this->assertNotNull($revoked->revoked_at, 'a retired identity is stamped, not deleted');
        }
    }

    public function test_each_provisioning_issues_a_different_username_and_password(): void
    {
        $bankA = LockerBank::factory()->create(['provisioned_at' => now(), 'provisioning_generation' => (string) Str::uuid()]);
        $bankB = LockerBank::factory()->create(['provisioned_at' => now(), 'provisioning_generation' => (string) Str::uuid()]);

        $seen = [];
        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use (&$seen): void {
            $mock->shouldReceive('publishSuccess')
                ->twice()
                ->andReturnUsing(function (LockerWasProvisioned $e, string $user, string $password) use (&$seen): void {
                    $seen[] = [$user, $password];
                });
        });

        $reactor = app(MqttReactor::class);
        foreach ([$bankA, $bankB] as $bank) {
            $reactor->onLockerWasProvisioned(new LockerWasProvisioned(
                lockerBankUuid: (string) $bank->id,
                replyToTopic: 'locker/provisioning/reply/test-client',
                provisioningGeneration: (string) $bank->provisioning_generation,
            ));
        }

        $this->assertNotSame($seen[0][0], $seen[1][0], 'usernames must differ');
        $this->assertNotSame($seen[0][1], $seen[1][1], 'passwords must differ');
        $this->assertNotSame((string) $bankA->id, $seen[0][0], 'the username is not the locker uuid');
    }
}
