<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The unlock pulse was sent but the door never opened within the detection
 * window: a jam, a blocked or held door, a worn latch, or a failed sensor.
 *
 * Recorded instead of the success this used to be reported as (ADR-0031).
 */
class CompartmentOpenNotDetected extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $transactionId,
        public readonly ?string $errorCode = null,
        public readonly ?string $timestamp = null,
    ) {}
}
