<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Someone was added to an organization. Recorded for new and existing accounts
 * alike, so the operator's audit log shows who brought each person in — an
 * existing account joins silently, and this is where that becomes visible.
 */
#[Audited(AuditCategory::Admin, 'User joined organization')]
class UserJoinedOrganization extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $organizationId,
        public readonly ?int $actorUserId,
        public readonly bool $existingAccount,
        public readonly string $joinedAt,
    ) {}
}
