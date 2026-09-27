<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Devices, 'Connection lost')]
class LockerConnectionLost extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $detectedAtIso8601,
        public readonly ?string $lastHeartbeatAtIso8601 = null,
        public readonly string $reason = 'timeout',
    ) {}
}
