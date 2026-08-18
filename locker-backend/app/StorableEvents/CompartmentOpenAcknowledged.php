<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The locker acknowledged the open command: the unlock pulse was sent.
 *
 * This is command execution, not a physical outcome. Whether the door opened is
 * reported separately. This fact used to be recorded as
 * CompartmentOpened, which conflated the two.
 */
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
