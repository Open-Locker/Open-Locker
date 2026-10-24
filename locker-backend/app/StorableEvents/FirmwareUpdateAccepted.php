<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The locker accepted the update and started downloading. Not the end of the
 * update: that is a heartbeat reporting the new version (ADR-0076).
 */
#[Audited(AuditCategory::Devices, 'Firmware update accepted')]
class FirmwareUpdateAccepted extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly ?string $timestamp = null,
    ) {}
}
