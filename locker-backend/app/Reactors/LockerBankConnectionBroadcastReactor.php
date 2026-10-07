<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\LockerBankConnectionUpdated;
use App\Models\LockerBank;
use App\Services\CompartmentStatusBroadcastService;
use App\StorableEvents\LockerConnectionLost;
use App\StorableEvents\LockerConnectionRestored;
use App\StorableEvents\LockerProvisioningReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Broadcasts a bank going offline, coming back, or having its provisioning
 * reset, so the app stops having to refetch to notice.
 *
 * Coming back is immediate: a heartbeat arrives and the handler records the
 * event. Going offline cannot be, since nothing observes an absence — it waits
 * for the `locker:detect-offline` sweep, so a bank shows online for up to its
 * heartbeat timeout after it actually dies.
 */
class LockerBankConnectionBroadcastReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function __construct(
        private readonly CompartmentStatusBroadcastService $broadcastService,
    ) {}

    public function onLockerConnectionLost(LockerConnectionLost $event): void
    {
        $this->broadcast($event->lockerBankUuid, 'offline', $event->detectedAtIso8601);
    }

    public function onLockerConnectionRestored(LockerConnectionRestored $event): void
    {
        $this->broadcast($event->lockerBankUuid, 'online', $event->restoredAtIso8601);
    }

    /**
     * A reset revokes the device's credentials, and the projector marks the bank
     * offline. Without this the app would keep showing it online until something
     * else made it refetch.
     */
    public function onLockerProvisioningReset(LockerProvisioningReset $event): void
    {
        $this->broadcast($event->lockerBankUuid, 'offline', $event->resetAtIso8601);
    }

    private function broadcast(string $lockerBankUuid, string $status, string $changedAtIso): void
    {
        // Queued, so no organization is in context. The uuid comes from the
        // event being handled rather than from a request, and it is globally
        // unique — so there is nothing here for a scope to protect. Scoped,
        // this resolves to nothing and the broadcast is silently skipped: the
        // app shows a stale door until someone reloads the screen.
        $lockerBank = LockerBank::withoutGlobalScope('organization')->find($lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $recipientIds = $this->broadcastService->recipientUserIdsForLockerBank($lockerBank);
        if ($recipientIds === []) {
            return;
        }

        event(new LockerBankConnectionUpdated(
            recipientUserIds: $recipientIds,
            lockerBankUuid: $lockerBankUuid,
            connectionStatus: $status,
            connectionStatusChangedAtIso: $changedAtIso,
            lastHeartbeatAtIso: $lockerBank->last_heartbeat_at?->toIso8601String(),
        ));
    }
}
