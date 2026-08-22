<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * The compartment door was already open when the unlock pulse was sent.
 *
 * A deviation rather than a plain success: the compartment was accessible before
 * anyone was authorized to open it.
 */
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
