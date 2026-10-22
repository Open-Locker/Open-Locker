<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The update ended without a new image: the locker refused the command (an
 * error response), or failed after accepting it (the firmware_update_failed
 * device event). Both carry one of the UPDATE_* codes of ADR-0076.
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
    ) {}
}
