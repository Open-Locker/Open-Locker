<?php

declare(strict_types=1);

namespace App\StorableEvents;

/**
 * Legacy telemetry event.
 *
 * We intentionally do NOT store individual heartbeats in the event store because they can be
 * extremely high-frequency. We only store derived connection transition events.
 *
 * Note: Do not delete this class if you already have historical stored events referencing
 * App\StorableEvents\HeartbeatReceived. Spatie will need the class to deserialize/replay them.
 */
class HeartbeatReceived
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank sending the heartbeat
     * @param  string|null  $timestamp  ISO8601 timestamp provided by the client (optional)
     * @param  array<string,mixed>  $data  Arbitrary telemetry payload
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly ?string $timestamp = null,
        public readonly array $data = [],
    ) {}
}
