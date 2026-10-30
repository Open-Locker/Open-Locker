<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * A heartbeat reported the target version outside its trial: the only proof
 * that the new image booted and kept itself (ADR-0076, ADR-0077).
 */
#[Audited(AuditCategory::Devices, 'Firmware update completed')]
class FirmwareUpdateCompleted extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly string $version,
        public readonly ?string $timestamp = null,
    ) {}
}
