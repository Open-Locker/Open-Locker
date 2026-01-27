<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerConfigAckFailed extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?string $timestamp = null,
    ) {}
}
