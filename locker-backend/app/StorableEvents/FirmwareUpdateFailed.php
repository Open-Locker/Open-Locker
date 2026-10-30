<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The update ended without a new image: the locker refused the command (an
 * error response), or failed after accepting it (the firmware_update_failed
 * device event), both with one of the UPDATE_* codes of ADR-0076 in `errorCode`.
 * When the backend ends the update itself, `errorCode` is empty and `reason`
 * says why: a rollback seen in a heartbeat, or a timeout (ADR-0077).
 */
#[Audited(AuditCategory::Devices, 'Firmware update failed')]
class FirmwareUpdateFailed extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?string $timestamp = null,
        public readonly ?string $reason = null,
    ) {}
}
