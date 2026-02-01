<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerConnectionLost extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $detectedAtIso8601,
        public readonly ?string $lastHeartbeatAtIso8601 = null,
        public readonly string $reason = 'timeout',
    ) {}
}
