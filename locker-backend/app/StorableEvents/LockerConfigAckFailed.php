<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Devices, 'Configuration failed')]
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
