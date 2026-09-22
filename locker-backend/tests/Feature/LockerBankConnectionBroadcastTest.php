<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\LockerBankConnectionUpdated;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\LockerBank;
use App\Models\User;
use App\Reactors\LockerBankConnectionBroadcastReactor;
use App\StorableEvents\LockerConnectionLost;
use App\StorableEvents\LockerConnectionRestored;
use App\StorableEvents\LockerProvisioningReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LockerBankConnectionBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function bankWithAccessHolder(): array
    {
        $lockerBank = LockerBank::factory()->create();
        $compartment = Compartment::factory()->for($lockerBank)->create();
        $user = User::factory()->create();

        CompartmentAccess::factory()->create([
            'compartment_id' => $compartment->id,
            'user_id' => $user->id,
        ]);

        return [$lockerBank, $user];
    }

    public function test_connection_lost_broadcasts_offline_to_access_holders(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);
        [$lockerBank, $user] = $this->bankWithAccessHolder();

        $detectedAt = now()->toIso8601String();

        app(LockerBankConnectionBroadcastReactor::class)->onLockerConnectionLost(new LockerConnectionLost(
            lockerBankUuid: (string) $lockerBank->id,
            detectedAtIso8601: $detectedAt,
        ));

        Event::assertDispatched(
            LockerBankConnectionUpdated::class,
            function (LockerBankConnectionUpdated $event) use ($lockerBank, $user, $detectedAt): bool {
                return $event->lockerBankUuid === (string) $lockerBank->id
                    && $event->connectionStatus === 'offline'
                    && $event->connectionStatusChangedAtIso === $detectedAt
                    && in_array($user->id, $event->recipientUserIds, true);
            }
        );
    }

    public function test_connection_restored_broadcasts_online(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);
        [$lockerBank, $user] = $this->bankWithAccessHolder();

        app(LockerBankConnectionBroadcastReactor::class)->onLockerConnectionRestored(new LockerConnectionRestored(
            lockerBankUuid: (string) $lockerBank->id,
            restoredAtIso8601: now()->toIso8601String(),
        ));

        Event::assertDispatched(
            LockerBankConnectionUpdated::class,
            fn (LockerBankConnectionUpdated $event): bool => $event->connectionStatus === 'online'
                && in_array($user->id, $event->recipientUserIds, true)
        );
    }

    public function test_provisioning_reset_broadcasts_offline(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);
        [$lockerBank, $user] = $this->bankWithAccessHolder();

        $resetAt = now()->toIso8601String();

        // The projector marks a reset bank offline by writing the column directly,
        // so without this handler the app would keep showing it online.
        app(LockerBankConnectionBroadcastReactor::class)->onLockerProvisioningReset(new LockerProvisioningReset(
            lockerBankUuid: (string) $lockerBank->id,
            actorUserId: $user->id,
            resetAtIso8601: $resetAt,
        ));

        Event::assertDispatched(
            LockerBankConnectionUpdated::class,
            function (LockerBankConnectionUpdated $event) use ($lockerBank, $user, $resetAt): bool {
                return $event->lockerBankUuid === (string) $lockerBank->id
                    && $event->connectionStatus === 'offline'
                    && $event->connectionStatusChangedAtIso === $resetAt
                    && in_array($user->id, $event->recipientUserIds, true);
            }
        );
    }

    public function test_it_broadcasts_on_its_own_channel(): void
    {
        [$lockerBank, $user] = $this->bankWithAccessHolder();

        $event = new LockerBankConnectionUpdated(
            recipientUserIds: [$user->id],
            lockerBankUuid: (string) $lockerBank->id,
            connectionStatus: 'offline',
        );

        $channels = array_map(static fn ($channel): string => (string) $channel, $event->broadcastOn());

        // Its own channel, per ADR-0056's rule that a channel is named after what
        // it carries.
        $this->assertSame(["private-users.{$user->id}.locker-banks"], $channels);
        $this->assertSame('locker_bank.connection.updated', $event->broadcastAs());
        $this->assertSame([
            'locker_bank_id' => (string) $lockerBank->id,
            'connection_status' => 'offline',
            'connection_status_changed_at' => null,
            'last_heartbeat_at' => null,
        ], $event->broadcastWith());
    }

    public function test_nothing_is_broadcast_for_an_unknown_bank(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);

        app(LockerBankConnectionBroadcastReactor::class)->onLockerConnectionLost(new LockerConnectionLost(
            lockerBankUuid: '99999999-9999-9999-9999-999999999999',
            detectedAtIso8601: now()->toIso8601String(),
        ));

        Event::assertNotDispatched(LockerBankConnectionUpdated::class);
    }

    public function test_nothing_is_broadcast_when_nobody_can_see_the_bank(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);

        // A bank with no compartments has no audience, so there is nobody to tell.
        $lockerBank = LockerBank::factory()->create();

        app(LockerBankConnectionBroadcastReactor::class)->onLockerConnectionLost(new LockerConnectionLost(
            lockerBankUuid: (string) $lockerBank->id,
            detectedAtIso8601: now()->toIso8601String(),
        ));

        Event::assertNotDispatched(LockerBankConnectionUpdated::class);
    }
}
