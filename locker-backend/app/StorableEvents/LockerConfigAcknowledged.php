<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerConfigAcknowledged extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly string $appliedConfigHash,
        public readonly ?string $timestamp = null,
    ) {}
}
