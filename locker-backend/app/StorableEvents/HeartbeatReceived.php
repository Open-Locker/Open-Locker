<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class HeartbeatReceived extends ShouldBeStored
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
