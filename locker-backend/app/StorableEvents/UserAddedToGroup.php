<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Admin, 'User added to group')]
class UserAddedToGroup extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly int $userId,
        public readonly int $actorUserId,
        public readonly string $addedAt,
        public readonly ?string $expiresAt = null,
    ) {}
}
