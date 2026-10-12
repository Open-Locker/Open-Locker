<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Admin, 'Role revoked')]
class UserRoleRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $actorUserId,
        public readonly string $revokedAt,
    ) {}
}
