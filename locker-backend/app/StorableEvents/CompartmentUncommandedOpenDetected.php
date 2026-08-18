<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A compartment door was observed opening with no relay fire behind it.
 *
 * Operationally a break-in, tampering, a faulty lock, or a failing sensor
 *. No correlation cutoff is applied: the age of the last relay fire
 * is recorded and thresholds live in alerting policy, so they can change without
 * rewriting history.
 */
class CompartmentUncommandedOpenDetected extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        /** Null when the relay has never fired for this compartment in the client session. */
        public readonly ?int $millisecondsSinceLastRelayFire = null,
        public readonly ?string $timestamp = null,
    ) {}
}
