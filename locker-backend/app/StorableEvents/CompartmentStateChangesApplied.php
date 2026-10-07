<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\NotAudited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Recorded once per MQTT compartment snapshot batch when at least one projected door state differs.
 * Correlates locker-level {@see LockerBank::$last_compartment_state_change_at} with that batch (not “last MQTT receipt”).
 */
#[NotAudited('Marker for a batch of sensor snapshot changes; telemetry.')]
class CompartmentStateChangesApplied extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $changesObservedAtIso8601,
        public readonly string $mqttMessageId,
    ) {}
}
