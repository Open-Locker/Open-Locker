<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Devices, 'Provisioning token issued')]
class LockerProvisioningTokenIssued extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $provisioningTokenHmac,
        public readonly string $provisioningGeneration,
        public readonly int $actorUserId,
        public readonly string $issuedAtIso8601,
    ) {}
}
