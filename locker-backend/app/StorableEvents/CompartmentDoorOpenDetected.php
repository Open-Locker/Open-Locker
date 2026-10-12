<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The compartment door was physically observed open after an unlock pulse.
 *
 * Distinct from the legacy CompartmentOpened, which recorded that the pulse was
 * sent. Any CompartmentOpened in the store predates that split and carries the old
 * meaning; this event is the door itself.
 */
#[Audited(AuditCategory::Access, 'Door opened')]
class CompartmentDoorOpenDetected extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $transactionId,
        /** Milliseconds from unlock pulse to the door being observed open. */
        public readonly ?int $detectionMs = null,
        public readonly ?string $timestamp = null,
    ) {}
}
