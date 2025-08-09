<?php

namespace App\Projectors;

use App\Models\LockerBank;
use App\StorableEvents\HeartbeatReceived;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class LockerBankProjector extends Projector implements ShouldQueue
{
    public function onLockerWasProvisioned(LockerWasProvisioned $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);

        if ($lockerBank) {
            $lockerBank->update(['provisioned_at' => now()]);
        }
    }

    public function onHeartbeatReceived(HeartbeatReceived $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $ts = $event->timestamp ? Carbon::parse($event->timestamp) : now();

        $lockerBank->forceFill([
            'last_heartbeat_at' => $ts,
        ])->save();
    }
}
