<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Access, 'Group access revoked')]
class GroupCompartmentAccessRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly string $revokedAt,
    ) {}
}
