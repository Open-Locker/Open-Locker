<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A user asked the locker managers for help with a compartment, usually after
 * it failed to open. The message is the user's own words and stays in the
 * event store with the rest of the audit trail, as does the optional call-back
 * phone (ADR-0063).
 */
class CompartmentHelpRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $helpRequestUuid,
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly string $message,
        public readonly string $requestedAtIso8601,
        // Optional number the user left for a call back; last and defaulted so
        // events stored before it existed still load.
        public readonly ?string $callbackPhone = null,
    ) {}
}
