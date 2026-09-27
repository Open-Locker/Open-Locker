<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The locker acknowledged the open command: the unlock pulse was sent.
 *
 * This is command execution, not a physical outcome. Whether the door opened is
 * reported separately. This fact used to be recorded as
 * CompartmentOpened, which conflated the two.
 */
#[Audited(AuditCategory::Access, 'Unlock pulse sent')]
class CompartmentOpenAcknowledged extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $transactionId,
        public readonly ?string $timestamp = null,
    ) {}
}
