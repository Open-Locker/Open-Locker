<?php

namespace App\Projectors;

use App\Models\LockerBank;
use App\StorableEvents\LockerConnectionLost;
use App\StorableEvents\LockerConnectionRestored;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Contracts\Queue\ShouldQueue;
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

    public function onLockerConnectionLost(LockerConnectionLost $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'connection_status' => 'offline',
            'connection_status_changed_at' => $event->detectedAtIso8601,
        ])->save();
    }

    public function onLockerConnectionRestored(LockerConnectionRestored $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'connection_status' => 'online',
            'connection_status_changed_at' => $event->restoredAtIso8601,
        ])->save();
    }
}
