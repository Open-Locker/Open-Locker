<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Domain signal that a compartment's effective door state changed according to MQTT snapshot telemetry.
 */
class CompartmentDoorStateChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $previousDoorState,
        public readonly string $newDoorState,
        public readonly string $doorStateChangedAtIso8601,
        public readonly string $mqttMessageId,
    ) {}
}
