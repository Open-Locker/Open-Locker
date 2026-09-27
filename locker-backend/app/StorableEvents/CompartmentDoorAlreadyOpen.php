<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The compartment door was already open when the unlock pulse was sent.
 *
 * A deviation rather than a plain success: the compartment was accessible before
 * anyone was authorized to open it.
 */
#[Audited(AuditCategory::Access, 'Door was already open')]
class CompartmentDoorAlreadyOpen extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $transactionId,
        public readonly ?string $timestamp = null,
    ) {}
}
