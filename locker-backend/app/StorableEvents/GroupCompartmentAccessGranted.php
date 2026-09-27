<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Access, 'Group access granted')]
class GroupCompartmentAccessGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly string $grantedAt,
        public readonly ?string $expiresAt = null,
        public readonly ?string $notes = null,
    ) {}
}
