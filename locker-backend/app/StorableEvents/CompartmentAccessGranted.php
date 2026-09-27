<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Access, 'Access granted')]
class CompartmentAccessGranted extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly int $actorUserId,
        public readonly string $compartmentUuid,
        public readonly string $grantedAt,
        public readonly ?string $expiresAt = null,
        public readonly ?string $notes = null,
    ) {}
}
